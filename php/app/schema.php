<?php
// Микроразметка schema.org (порт src/lib/schema.mjs).

function schema_graph(array $page, array $o = []): array
{
    $CO = canonical_origin();
    $ORG = $CO . '/#organization';
    $d = $page['data'] ?? [];
    $s = settings();
    $url = canonical_url(truthy($d['url'] ?? null) ? $d['url'] : '/');
    $g = [
        ['@type' => ['Organization', 'FurnitureStore'], '@id' => $ORG, 'name' => 'SIMMETRIKA', 'alternateName' => 'Симметрика', 'url' => $CO . '/', 'telephone' => $s['phone_main'], 'email' => $s['email'], 'sameAs' => [$s['vk']], 'areaServed' => 'Москва и Московская область'],
        ['@type' => 'WebSite', '@id' => $CO . '/#website', 'url' => $CO . '/', 'name' => 'SIMMETRIKA', 'publisher' => ['@id' => $ORG], 'inLanguage' => 'ru'],
    ];
    $types = ['home' => 'WebPage', 'hub' => 'CollectionPage', 'category' => 'CollectionPage', 'collection' => 'CollectionPage', 'filter' => 'CollectionPage', 'landing' => 'CollectionPage', 'product' => 'ItemPage', 'about' => 'AboutPage', 'contact' => 'ContactPage', 'news-list' => 'CollectionPage', 'news-item' => 'WebPage'];
    $t = truthy($o['type'] ?? null) ? $o['type'] : ($d['type'] ?? null);
    $wp = ['@type' => $types[jsstr($t)] ?? 'WebPage', '@id' => $url . '#webpage', 'url' => $url, 'name' => page_title($d)];
    if (array_key_exists('description', $d)) $wp['description'] = $d['description'];
    $wp['isPartOf'] = ['@id' => $CO . '/#website'];
    $wp['inLanguage'] = 'ru';
    if (truthy($d['date_modified'] ?? null)) $wp['dateModified'] = mb_substr(jsstr($d['date_modified']), 0, 10);
    $g[] = $wp;

    $bc = $o['breadcrumbs'] ?? [];
    if ($bc) {
        $items = [];
        foreach (array_values($bc) as $i => $b) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $b['name'], 'item' => canonical_url($b['url'])];
        $g[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    $p = $o['product'] ?? null;
    if ($p) {
        $prices = array_map(fn($m) => variant_price($p, $m), ['rogozhka', 'velyur', 'zhakkard']);
        $images = [];
        foreach ($p['photos'] ?? [] as $ph) { $l = img($ph)['l'] ?? null; if (truthy($l)) $images[] = $CO . $l; }
        $props = [];
        if (truthy($p['length_cm'] ?? null)) {
            $props[] = ['@type' => 'PropertyValue', 'name' => 'Длина', 'value' => $p['length_cm'], 'unitCode' => 'CMT'];
            $props[] = ['@type' => 'PropertyValue', 'name' => 'Глубина', 'value' => $p['depth_cm'] ?? null, 'unitCode' => 'CMT'];
        }
        $props[] = ['@type' => 'PropertyValue', 'name' => 'Механизм', 'value' => $p['mechanism_name'] ?? null];
        if (truthy($p['sleeping'] ?? null)) $props[] = ['@type' => 'PropertyValue', 'name' => 'Спальное место', 'value' => jsstr($p['sleeping']['w']) . '×' . jsstr($p['sleeping']['l']) . ' см'];
        $pr = ['@type' => 'Product', '@id' => canonical_url($p['url']) . '#product', 'name' => $p['h1'], 'sku' => replace_first('/', '-', $p['id']), 'brand' => ['@type' => 'Brand', 'name' => 'SIMMETRIKA'], 'manufacturer' => ['@id' => $ORG], 'image' => $images];
        if (array_key_exists('description', $d)) $pr['description'] = $d['description'];
        $pr['additionalProperty'] = $props;
        if (truthy($p['price_from'] ?? null)) {
            $pr['offers'] = ['@type' => 'AggregateOffer', 'priceCurrency' => 'RUB', 'lowPrice' => min($prices), 'highPrice' => max($prices), 'offerCount' => 3, 'availability' => truthy($p['in_stock'] ?? null) ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder', 'seller' => ['@id' => $ORG]];
        }
        $g[] = $pr;
    }

    if (($d['type'] ?? null) === 'contact') {
        foreach (showrooms() as $r) {
            $x = ['@type' => 'FurnitureStore', '@id' => $CO . '/kontakty/#' . $r['slug'], 'name' => 'SIMMETRIKA, ' . $r['name'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $r['address'], 'addressCountry' => 'RU']];
            if (truthy($r['lat'] ?? null)) $x['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $r['lat'], 'longitude' => $r['lon']];
            $x['telephone'] = $r['phone'] ?? null;
            $x['openingHours'] = 'Mo-Su ' . replace_first('–', '-', jsstr($r['hours']));
            $x['parentOrganization'] = ['@id' => $ORG];
            $g[] = $x;
        }
    }

    $faq = $o['faq'] ?? null;
    if (is_array($faq) && $faq) {
        $q = [];
        foreach ($faq as $x) $q[] = ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]];
        $g[] = ['@type' => 'FAQPage', 'mainEntity' => $q];
    }
    return ['@context' => 'https://schema.org', '@graph' => $g];
}
