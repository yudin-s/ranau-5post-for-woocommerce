<?php

namespace Ranau\FivePost;

defined('ABSPATH') || exit;

final class Package
{
    private float $weight_kg;
    private float $length_cm;
    private float $width_cm;
    private float $height_cm;

    public function __construct(float $weight_kg, float $length_cm, float $width_cm, float $height_cm)
    {
        $this->weight_kg = max(0.0, $weight_kg);
        $dimensions = array($length_cm, $width_cm, $height_cm);
        rsort($dimensions, SORT_NUMERIC);

        $this->length_cm = max(0.0, (float) $dimensions[0]);
        $this->width_cm = max(0.0, (float) $dimensions[1]);
        $this->height_cm = max(0.0, (float) $dimensions[2]);
    }

    public function weight_kg(): float
    {
        return $this->weight_kg;
    }

    public function dimensions_desc(): array
    {
        return array($this->length_cm, $this->width_cm, $this->height_cm);
    }
}
