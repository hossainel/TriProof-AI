<?php

namespace App\Services;

class DppService
{
    /** Deterministic canonical digest so the same provenance data always yields the same label. */
    public function digest(array $metadata): string
    {
        $canonical = $this->canonical($metadata);
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtoupper(substr(hash('sha256', $json), 0, 8));
    }

    public function normalize(array $metadata): array
    {
        return $this->canonical($metadata);
    }

    public function scorecard(array $metadata): array
    {
        $canonical = $this->canonical($metadata);
        $score = 60;

        foreach (['oeko_tex_id', 'gots_id', 'bsci_id'] as $certificate) {
            if (! empty($canonical[$certificate])) {
                $score += 10;
            }
        }
        if (isset($canonical['carbon_footprint_kg'])) {
            $score += (float) $canonical['carbon_footprint_kg'] <= 10 ? 10 : 0;
        }
        if (isset($canonical['water_footprint_liters'])) {
            $score += (float) $canonical['water_footprint_liters'] <= 100 ? 10 : 0;
        }

        return [
            'score' => min(100, $score),
            'yarn_mill' => $canonical['yarn_mill'] ?? null,
            'dye_house' => $canonical['dye_house'] ?? null,
            'fabric_composition' => $canonical['fabric_composition'] ?? null,
            'certifications' => [
                'oeko_tex' => $canonical['oeko_tex_id'] ?? null,
                'gots' => $canonical['gots_id'] ?? null,
                'bsci' => $canonical['bsci_id'] ?? null,
            ],
            'carbon_footprint_kg' => $canonical['carbon_footprint_kg'] ?? null,
            'water_footprint_liters' => $canonical['water_footprint_liters'] ?? null,
        ];
    }

    private function canonical(array $metadata): array
    {
        $allowed = [
            'yarn_mill', 'dye_house', 'fabric_composition', 'oeko_tex_id', 'gots_id', 'bsci_id',
            'carbon_footprint_kg', 'water_footprint_liters', 'country_of_origin', 'recycled_content_percent',
        ];

        $clean = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $metadata) && $metadata[$field] !== null && $metadata[$field] !== '') {
                $clean[$field] = is_scalar($metadata[$field]) ? $metadata[$field] : null;
            }
        }
        ksort($clean);

        return $clean;
    }
}
