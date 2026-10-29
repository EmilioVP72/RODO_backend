<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class RestaurantCodeService
{
    private const MAX_CODES = 1000;

    public function createRestaurant(string $name): Restaurant
    {
        $name = trim($name);
        $normalized = mb_strtolower($name);

        if (Restaurant::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
            throw new \InvalidArgumentException('Ya existe un restaurante registrado con este nombre.');
        }

        if (Restaurant::count() >= self::MAX_CODES) {
            throw new RuntimeException(
                'No hay códigos disponibles.'
            );
        }

        for ($attempt = 0; $attempt < 30; $attempt++) {

            $code = str_pad(
                (string) random_int(0, 999),
                3,
                '0',
                STR_PAD_LEFT
            );

            if (Restaurant::where('code', $code)->exists()) {
                continue;
            }

            try {
                return Restaurant::create([
                    'name' => $name,
                    'code' => $code,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if (Restaurant::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
                    throw new \InvalidArgumentException('Ya existe un restaurante registrado con este nombre.');
                }
                continue;
            }
        }

        $codes = range(0, 999);
        shuffle($codes);

        foreach ($codes as $number) {
            $code = str_pad(
                (string) $number,
                3,
                '0',
                STR_PAD_LEFT
            );

            if (Restaurant::where('code', $code)->exists()) {
                continue;
            }

            try {
                return Restaurant::create([
                    'name' => $name,
                    'code' => $code,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if (Restaurant::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists()) {
                    throw new \InvalidArgumentException('Ya existe un restaurante registrado con este nombre.');
                }
                continue;
            }
        }

        throw new RuntimeException(
            'Se agotaron los códigos de restaurantes.'
        );
    }
}