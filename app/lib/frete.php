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
 *   - 'google'    -> Distance Matrix (implementar quando houver a chave)
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

/** Google Distance Matrix (driving). Origem = loja_lat,loja_lng. */
function _frete_km_google(string $destino): ?float
{
    // TODO (quando houver maps_api_key): chamar a Distance Matrix API via cURL
    // com origins=loja_lat,loja_lng, destinations=$destino, mode=driving, e
    // devolver rows[0].elements[0].distance.value / 1000. Null em qualquer falha.
    return null;
}

/** Linha reta (haversine) a partir de coordenadas geocodificadas, * fator. */
function _frete_km_haversine(string $destino): ?float
{
    // TODO: geocodificar $destino -> lat/lng; calcular haversine até
    // loja_lat/loja_lng e multiplicar por um fator (~1.3). Null em falha.
    return null;
}
