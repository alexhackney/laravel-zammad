<?php

declare(strict_types=1);

use Illuminate\Support\Env;

it('preserves filter defaults and reads boolean and marker environment overrides', function (): void {
    $defaults = [
        'filter_images' => true,
        'filter_tables' => true,
        'filter_signature_marker' => true,
        'filter_signature_marker_value' => '<span class="js-signatureMarker"></span>',
        'filter_data_signature' => true,
        'filter_data_signature_value' => '<div data-signature="true" data-signature-id="1">',
    ];
    $repository = Env::getRepository();
    $previous = [];

    try {
        foreach ($defaults as $key => $value) {
            $name = 'ZAMMAD_'.strtoupper($key);
            $previous[$name] = $repository->get($name);
            $repository->clear($name);
        }

        $config = require dirname(__DIR__, 3).'/config/zammad.php';
        expect(array_intersect_key($config, $defaults))->toBe($defaults);

        foreach (['false', 'true'] as $boolean) {
            foreach ($defaults as $key => $value) {
                $repository->set('ZAMMAD_'.strtoupper($key), is_bool($value) ? $boolean : 'custom-marker-'.$key);
            }

            $config = require dirname(__DIR__, 3).'/config/zammad.php';
            foreach ($defaults as $key => $value) {
                expect($config[$key])->toBe(is_bool($value) ? $boolean === 'true' : 'custom-marker-'.$key);
            }
        }
    } finally {
        foreach ($previous as $name => $value) {
            $value === null ? $repository->clear($name) : $repository->set($name, $value);
        }
    }
});
