<?php

namespace Bx\Imshop\Integration\Sale;

use Bitrix\Main\Loader;
use Bx\Imshop\Integration\Config;

/**
 * Shop list for the IMSHOP store map. The iblock is the contacts store list,
 * with ADDRESS, PHONE, SCHEDULE, MAP and METRO.
 */
final class StoreMap
{
    /**
     * @return list<array<string, string>>
     */
    public function list(): array
    {
        $iblockId = Config::storesIblockId();
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            return [];
        }

        $dbList = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
            ],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_SECTION_ID']
        );
        if (!is_object($dbList)) {
            return [];
        }

        $rows = [];
        $sectionIds = [];
        while ($element = $dbList->GetNextElement()) {
            $fields = $element->GetFields();
            $name = trim((string) ($fields['~NAME'] ?? $fields['NAME'] ?? ''));
            $id = (int) ($fields['ID'] ?? 0);
            if ($id <= 0 || $name === '') {
                continue;
            }

            $sectionId = (int) ($fields['IBLOCK_SECTION_ID'] ?? 0);
            if ($sectionId > 0) {
                $sectionIds[$sectionId] = $sectionId;
            }

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sectionId' => $sectionId,
                'properties' => $element->GetProperties(),
            ];
        }

        $cities = $this->sectionNames($iblockId, array_values($sectionIds));
        $stores = [];
        foreach ($rows as $row) {
            $stores[] = $this->store($row, $cities);
        }

        return $stores;
    }

    /**
     * @param list<int> $sectionIds
     * @return array<int, string>
     */
    private function sectionNames(int $iblockId, array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $names = [];
        $sections = \CIBlockSection::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'ID' => $sectionIds,
            ],
            false,
            ['ID', 'NAME']
        );
        while ($section = $sections->Fetch()) {
            if (!is_array($section)) {
                continue;
            }
            $id = (int) ($section['ID'] ?? 0);
            $name = trim((string) ($section['NAME'] ?? ''));
            if ($id > 0 && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array{id: int, name: string, sectionId: int, properties: array<string, mixed>} $row
     * @param array<int, string> $cities
     * @return array<string, string>
     */
    private function store(array $row, array $cities): array
    {
        $properties = $row['properties'];
        $store = [
            'id' => (string) $row['id'],
            'name' => $row['name'],
            'address' => $this->text($this->property($properties, 'ADDRESS')),
            'line1' => $this->schedule(is_array($properties['SCHEDULE'] ?? null) ? $properties['SCHEDULE'] : []),
            'line2' => '',
        ];

        $city = $cities[$row['sectionId']] ?? '';
        if ($city !== '') {
            $store['city'] = $city;
        }

        $phone = $this->join($this->property($properties, 'PHONE'));
        if ($phone !== '') {
            $store['phone'] = $phone;
        }

        $subway = $this->join($this->property($properties, 'METRO'));
        if ($subway !== '') {
            $store['subway'] = $subway;
        }

        $coords = $this->coords($this->text($this->property($properties, 'MAP')));
        if ($coords !== null) {
            $store['lat'] = $coords['lat'];
            $store['lon'] = $coords['lon'];
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function property(array $properties, string $code): mixed
    {
        $property = $properties[$code] ?? null;
        if (!is_array($property)) {
            return '';
        }

        return $property['~VALUE'] ?? $property['VALUE'] ?? '';
    }

    /**
     * @param array<string, mixed> $property
     */
    private function schedule(array $property): string
    {
        $value = $property['~VALUE'] ?? $property['VALUE'] ?? '';
        if (is_array($value) && array_key_exists('TEXT', $value)) {
            return $this->text($value['TEXT']);
        }

        return $this->join($value);
    }

    private function join(mixed $value): string
    {
        if (!is_array($value)) {
            return $this->text($value);
        }
        if (array_key_exists('TEXT', $value)) {
            return $this->text($value['TEXT']);
        }

        $parts = [];
        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('TEXT', $item)) {
                $item = $item['TEXT'];
            }
            $text = $this->text($item);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(', ', $parts);
    }

    private function text(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }

    /**
     * @return array{lat: string, lon: string}|null
     */
    private function coords(string $map): ?array
    {
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $map, $matches) !== 1) {
            return null;
        }

        $lat = (float) $matches[1];
        $lon = (float) $matches[2];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return [
            'lat' => number_format($lat, 6, '.', ''),
            'lon' => number_format($lon, 6, '.', ''),
        ];
    }
}
