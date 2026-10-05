<?php

/**
 * Frete por distância (dinheiro sempre em CENTAVOS).
 *
 * Regra do valor (definida): primeiros N km custam um valor fixo; cada km extra
 * (arredondado para CIMA) soma uma taxa por km. Ex.: base 5 km = R$ 9,00 e
 * R$ 1,00/km -> 11 km = 9 + ceil(11-5)*1 = R$ 15,00.
 *
 * O CÁLCULO DA DISTÂNCIA é plugável (settings.frete_provedor):
 *   - 'off'       -> ainda não calcula (retorna null; o checkout usa fallback)
 *   - 'google'    -> Google Routes API (chave em GOOGLE_MAPS_API_KEY no .env)
 *   - 'haversine' -> geocode + linha reta * fator (implementar)
 * Assim tudo já funciona (retirada 100%, motoboy com fallback) e, ao decidir o
 * provedor, basta preencher o driver correspondente — nada mais muda.
 */

/** Valor do frete (centavos) a partir da distância em km. */
function frete_por_distancia(float $km): int
{
    $base_km   = (int) cfg('frete_base_km', 5);
    $base_cent = (int) cfg('frete_base_centavos', 900);
    $km_cent   = (int) cfg('frete_por_km_centavos', 100);

    if ($km <= $base_km) {
        return $base_cent;
    }
    $extra_km = (int) ceil($km - $base_km); // km extra arredondado para cima
    return $base_cent + $extra_km * $km_cent;
}

/**
 * Distância (km) da loja até o destino, pelo provedor configurado. Só ida.
 * Retorna null quando indisponível (sem provedor / falha) — o chamador decide
 * o fallback. Usa cache (frete_cache) pela chave normalizada (ex.: cep+número).
 */
function frete_distancia_km(string $destino, ?string $chave_cache = null): ?float
{
    $provedor = cfg('frete_provedor', 'off');
    $destino  = trim($destino);
    if ($provedor === 'off' || $destino === '') {
        return null;
    }

    $chave = $chave_cache !== null ? trim($chave_cache) : mb_strtolower($destino);
    // Prefixo da origem: mudar provedor/coordenadas/endereço da loja invalida o cache antigo.
    $origem = $provedor . '|' . cfg('loja_lat', '') . '|' . cfg('loja_lng', '') . '|' . cfg('loja_endereco', '');
    $chave = substr(md5($origem), 0, 8) . ':' . mb_substr($chave, 0, 180);

    // 1) Cache
    $st = db()->prepare('SELECT distancia_km FROM frete_cache WHERE chave = ? LIMIT 1');
    $st->execute([$chave]);
    $cache = $st->fetchColumn();
    if ($cache !== false && $cache !== null) {
        return (float) $cache;
    }

    // 2) Provedor
    $km = null;
    if ($provedor === 'google') {
        $km = _frete_km_google($destino);
    } elseif ($provedor === 'haversine') {
        $km = _frete_km_haversine($destino);
    }

    // 3) Grava no cache (se obteve)
    if ($km !== null) {
        db()->prepare(
            'INSERT INTO frete_cache (chave, distancia_km) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE distancia_km = VALUES(distancia_km)'
        )->execute([$chave, $km]);
    }
    return $km;
}

/**
 * Orquestra o frete do checkout.
 *   $tipo: 'retirada' | 'motoboy'
 * Retorna: ['ok'=>bool, 'tipo'=>..., 'distancia_km'=>?float,
 *           'frete_centavos'=>?int, 'motivo'=>?string, 'mensagem'=>?string]
 */
function frete_calcular(string $tipo, string $destino = '', ?string $chave_cache = null): array
{
    if ($tipo === 'retirada') {
        return [
            'ok' => true, 'tipo' => 'retirada', 'distancia_km' => null,
            'frete_centavos' => 0, 'motivo' => null,
            'mensagem' => 'Retirada no local (sem taxa).',
        ];
    }

    // motoboy
    $km = frete_distancia_km($destino, $chave_cache);
    if ($km === null) {
        return [
            'ok' => false, 'tipo' => 'motoboy', 'distancia_km' => null,
            'frete_centavos' => null, 'motivo' => 'sem_distancia',
            'mensagem' => 'Não foi possível calcular o frete agora. Escolha retirada '
                        . 'ou combine a entrega pelo WhatsApp.',
        ];
    }

    $raio = (float) cfg('entrega_raio_max_km', 0);
    if ($raio > 0 && $km > $raio) {
        return [
            'ok' => false, 'tipo' => 'motoboy', 'distancia_km' => $km,
            'frete_centavos' => null, 'motivo' => 'fora_raio',
            'mensagem' => 'Endereço fora da área de entrega por motoboy. '
                        . 'Disponível apenas para retirada.',
        ];
    }

    return [
        'ok' => true, 'tipo' => 'motoboy', 'distancia_km' => $km,
        'frete_centavos' => frete_por_distancia($km), 'motivo' => null, 'mensagem' => null,
    ];
}

// -----------------------------------------------------------------------------
// Drivers de distância — implementar quando o provedor for escolhido.
// -----------------------------------------------------------------------------

/**
 * Google Routes API (computeRoutes). Origem = loja_lat,loja_lng; sem
 * coordenadas, usa o texto de loja_endereco. Tenta rota de moto e, se a região
 * não tiver, de carro. Null em qualquer falha (sem chave, sem origem, erro HTTP).
 */
function _frete_km_google(string $destino): ?float
{
    $key = (string) env('GOOGLE_MAPS_API_KEY', '');
    if ($key === '') {
        return null;
    }

    $lat = str_replace(',', '.', trim((string) cfg('loja_lat', '')));
    $lng = str_replace(',', '.', trim((string) cfg('loja_lng', '')));
    if (is_numeric($lat) && is_numeric($lng)) {
        $origem = ['location' => ['latLng' => ['latitude' => (float) $lat, 'longitude' => (float) $lng]]];
    } elseif (trim((string) cfg('loja_endereco', '')) !== '') {
        $origem = ['address' => trim((string) cfg('loja_endereco', '')) . ', Brasil'];
    } else {
        return null;
    }

    foreach (['TWO_WHEELER', 'DRIVE'] as $modo) {
        $metros = _frete_google_rota($key, $origem, $destino . ', Brasil', $modo);
        if ($metros !== null) {
            return round($metros / 1000, 2);
        }
    }
    return null;
}

/** Uma chamada ao computeRoutes. Devolve a distância em metros ou null. */
function _frete_google_rota(string $key, array $origem, string $destino, string $modo): ?int
{
    $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'origin'            => $origem,
            'destination'       => ['address' => $destino],
            'travelMode'        => $modo,
            'languageCode'      => 'pt-BR',
            'regionCode'        => 'BR',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: routes.distanceMeters',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http !== 200) {
        error_log('frete google: HTTP ' . $http . ' (' . $modo . ')');
        return null;
    }
    // Sem rota para o modo pedido a API responde 200 com {} — o chamador tenta o próximo.
    $m = json_decode($resp, true)['routes'][0]['distanceMeters'] ?? null;
    return is_numeric($m) ? (int) $m : null;
}

/** Linha reta (haversine) a partir de coordenadas geocodificadas, * fator. */
function _frete_km_haversine(string $destino): ?float
{
    // TODO: geocodificar $destino -> lat/lng; calcular haversine até
    // loja_lat/loja_lng e multiplicar por um fator (~1.3). Null em falha.
    return null;
}
