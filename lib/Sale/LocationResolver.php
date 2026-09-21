<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Sale\Location\ExternalTable;
use Bitrix\Sale\Location\LocationTable;
use Bitrix\Sale\Location\Search\Finder;

/**
 * IMSHOP addressData to a Bitrix location code (IS_LOCATION property value).
 */
final class LocationResolver
{
    private const LANGUAGE_ID = 'ru';

    /**
     * @param array<string, mixed> $payload
     */
    public function resolveCode(array $payload): ?string
    {
        $address = $payload['addressData'] ?? null;
        if (!is_array($address)) {
            $address = [];
        }

        foreach ($this->externalCodes($address) as $xmlId) {
            $code = $this->codeByExternalXmlId($xmlId);
            if ($code !== null) {
                return $code;
            }
        }

        $city = $this->firstString($address, ['city', 'settlement']);
        if ($city === '' && isset($payload['city']) && is_string($payload['city'])) {
            $city = trim($payload['city']);
        }
        if ($city === '') {
            return null;
        }

        $region = $this->firstString($address, ['region']);

        return $this->codeByPhrase($city, $region);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function zip(array $payload): string
    {
        $address = $payload['addressData'] ?? null;
        if (!is_array($address)) {
            return '';
        }

        return $this->firstString($address, ['zip']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addressValue(array $payload): string
    {
        $address = $payload['addressData'] ?? null;
        if (!is_array($address)) {
            return '';
        }

        return $this->firstString($address, ['value']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function cityName(array $payload): string
    {
        $address = $payload['addressData'] ?? null;
        if (!is_array($address)) {
            $address = [];
        }

        $city = $this->firstString($address, ['city', 'settlement']);
        if ($city === '' && isset($payload['city']) && is_string($payload['city'])) {
            return trim($payload['city']);
        }

        return $city;
    }

    /**
     * @param array<string, mixed> $address
     * @return list<string>
     */
    private function externalCodes(array $address): array
    {
        $codes = [];
        foreach (['cityFias', 'fias', 'fias_id', 'settlementFias', 'cityKladr', 'city_kladr', 'kladr'] as $key) {
            $value = $this->firstString($address, [$key]);
            if ($value !== '') {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }

    private function codeByExternalXmlId(string $xmlId): ?string
    {
        $external = ExternalTable::getList([
            'select' => ['LOCATION_ID'],
            'filter' => ['=XML_ID' => $xmlId],
            'limit' => 1,
        ])->fetch();

        if (!is_array($external)) {
            return null;
        }

        $locationId = (int) ($external['LOCATION_ID'] ?? 0);
        if ($locationId <= 0) {
            return null;
        }

        $location = LocationTable::getList([
            'select' => ['CODE'],
            'filter' => ['=ID' => $locationId],
            'limit' => 1,
        ])->fetch();

        if (!is_array($location)) {
            return null;
        }

        $code = trim((string) ($location['CODE'] ?? ''));

        return $code !== '' ? $code : null;
    }

    private function codeByPhrase(string $phrase, string $region): ?string
    {
        $result = Finder::find([
            'select' => [
                'ID',
                'CODE',
                'NAME' => 'NAME.NAME',
                'TYPE_CODE' => 'TYPE.CODE',
            ],
            'filter' => [
                '=PHRASE' => $phrase,
                '=NAME.LANGUAGE_ID' => self::LANGUAGE_ID,
            ],
            'limit' => 10,
        ]);

        $matches = [];
        while ($row = $result->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['CODE'] ?? ''));
            if ($code === '') {
                continue;
            }
            $matches[] = $row;
        }

        if ($matches === []) {
            return null;
        }

        $exact = array_values(array_filter(
            $matches,
            static fn (array $row): bool => mb_strtolower(trim((string) ($row['NAME'] ?? ''))) === mb_strtolower($phrase)
        ));
        $pool = $exact !== [] ? $exact : $matches;

        if ($region !== '') {
            foreach ($pool as $row) {
                $code = (string) $row['CODE'];
                if ($this->pathContainsName($code, $region)) {
                    return $code;
                }
            }
        }

        foreach ($pool as $row) {
            if ((string) ($row['TYPE_CODE'] ?? '') === 'CITY') {
                return (string) $row['CODE'];
            }
        }

        return (string) $pool[0]['CODE'];
    }

    private function pathContainsName(string $locationCode, string $name): bool
    {
        $path = LocationTable::getPathToNodeByCode($locationCode, [
            'select' => [
                'ID',
                'LOCATION_NAME' => 'NAME.NAME',
            ],
            'filter' => [
                '=NAME.LANGUAGE_ID' => self::LANGUAGE_ID,
            ],
        ]);
        if (!is_object($path) || !method_exists($path, 'fetch')) {
            return false;
        }

        $needle = mb_strtolower($name);
        while ($node = $path->fetch()) {
            if (!is_array($node)) {
                continue;
            }
            $nodeName = mb_strtolower(trim((string) ($node['LOCATION_NAME'] ?? '')));
            if ($nodeName !== '' && (str_contains($nodeName, $needle) || str_contains($needle, $nodeName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) || is_numeric($value)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }
}
