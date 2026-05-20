<?php

declare(strict_types=1);

namespace App\Catalog;

final class ProductNormalizer
{
    /**
     * Known brand aliases → canonical form.
     */
    private const BRAND_ALIASES = [
        'g&g'            => 'G&G Armament',
        'g&g armament'   => 'G&G Armament',
        'gg armament'    => 'G&G Armament',
        'tokyo marui'    => 'Tokyo Marui',
        'tm'             => 'Tokyo Marui',
        'cyma'           => 'CYMA',
        'specna arms'    => 'Specna Arms',
        'sa'             => 'Specna Arms',
        'asg'            => 'ASG',
        'action sport games' => 'ASG',
        'ics'            => 'ICS',
        'vfc'            => 'VFC',
        'we'             => 'WE',
        'we tech'        => 'WE',
        'kwa'            => 'KWA',
        'krytac'         => 'Krytac',
        'lct'            => 'LCT',
        'classic army'   => 'Classic Army',
        'e&l'            => 'E&L',
        'double eagle'   => 'Double Eagle',
        'jg'             => 'JG Works',
        'jg works'       => 'JG Works',
        'arcturus'       => 'Arcturus',
        'modify'         => 'Modify',
        'nuprol'         => 'Nuprol',
        'novritsch'      => 'Novritsch',
        'silverback'     => 'Silverback',
        'ares'           => 'Ares',
    ];

    /**
     * Words to remove from normalized names.
     */
    private const NOISE_WORDS = [
        'replica', 'airsoft', 'gun', 'rifle', 'pistol',
        'limited edition', 'new', 'hot', 'sale', 'promo',
        'free shipping', 'best seller', 'bestseller',
        '™', '®', '©',
    ];

    public function normalize(string $name, string $brand = ''): array
    {
        $normalizedName = $this->normalizeName($name);
        $normalizedBrand = $this->normalizeBrand($brand);
        $model = $this->extractModel($name, $normalizedBrand);

        return [
            'normalized_name' => $normalizedName,
            'brand'           => $normalizedBrand,
            'model'           => $model,
        ];
    }

    public function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        // Decode HTML entities
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove noise words
        foreach (self::NOISE_WORDS as $word) {
            $name = str_ireplace($word, ' ', $name);
        }

        // Keep color in name — different colors = different products

        // Normalize whitespace
        $name = preg_replace('/[^\w\s\-\.\/&+]/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    public function normalizeBrand(string $brand): string
    {
        $brand = trim($brand);
        if (empty($brand)) {
            return '';
        }

        $key = mb_strtolower($brand);
        if (isset(self::BRAND_ALIASES[$key])) {
            return self::BRAND_ALIASES[$key];
        }

        // Capitalize first letters
        return mb_convert_case($brand, MB_CASE_TITLE, 'UTF-8');
    }

    public function extractModel(string $name, string $brand = ''): string
    {
        $work = $name;

        // Remove brand from name
        if (!empty($brand)) {
            $work = preg_replace('/\b' . preg_quote($brand, '/') . '\b/iu', '', $work) ?? $work;
        }

        $work = trim($work, " \t\n\r\0\x0B-–—");

        // Try to find alphanumeric model patterns: M4A1, AK-47, MP5K, SA-E06, CM.045
        if (preg_match('/\b([A-Z]{1,5}[\-\.]?\d{1,5}[A-Z]?\d{0,3}(?:[\-\.]\d{1,3})?)\b/i', $work, $m)) {
            return strtoupper($m[1]);
        }

        // Try pattern like "Edge 2.0"
        if (preg_match('/\b(\w+\s+\d+(?:\.\d+)?)\b/i', $work, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Compute similarity score (0.0 - 1.0) between two normalized names.
     */
    public function similarity(string $a, string $b): float
    {
        $a = $this->normalizeName($a);
        $b = $this->normalizeName($b);

        if ($a === $b) {
            return 1.0;
        }

        if (empty($a) || empty($b)) {
            return 0.0;
        }

        // Levenshtein on short strings
        if (mb_strlen($a) < 255 && mb_strlen($b) < 255) {
            $maxLen = max(strlen($a), strlen($b));
            $distance = levenshtein($a, $b);
            $levenScore = 1.0 - ($distance / $maxLen);
        } else {
            $levenScore = 0.0;
        }

        // Similar text percentage
        similar_text($a, $b, $percent);
        $simScore = $percent / 100;

        // Token overlap (Jaccard)
        $tokensA = array_unique(explode(' ', $a));
        $tokensB = array_unique(explode(' ', $b));
        $intersection = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));
        $jaccardScore = $union > 0 ? $intersection / $union : 0.0;

        // Weighted combination
        return ($levenScore * 0.3) + ($simScore * 0.3) + ($jaccardScore * 0.4);
    }
}
