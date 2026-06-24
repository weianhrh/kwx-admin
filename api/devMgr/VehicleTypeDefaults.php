<?php
// api/devMgr/VehicleTypeDefaults.php

/**
 * 普通车辆默认控制配置
 * 适合：汽车、坦克、普通推土机、翻斗车、娃娃机等
 */
function normalControlDefaults(array $input = []): array
{
    return [
        'throttle_max' => isset($input['throttle_max']) ? (int)$input['throttle_max'] : 1640,
        'throttle_min' => isset($input['throttle_min']) ? (int)$input['throttle_min'] : 1305,
        'direction'    => isset($input['direction']) ? (string)$input['direction'] : 'true',
    ];
}

/**
 * 1 - 汽车默认配置
 */
function carControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 2 - 坦克默认配置
 */
function tankControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 3 - 挖掘机默认配置
 */
function excavatorControlDefaults(): array
{
    return [
        'car_category'  => 0,
        'driver_type'   => 0,

        'direction_max' => 1700,
        'direction_min' => 1300,
        'throttle_max'  => 1900,
        'throttle_min'  => 1100,

        'direction_mid' => 1500,
        'throttle_mid'  => 1500,
        'gear_position' => '高',
        'car_id'        => '',

        'direction'     => 'true',
        'throttle'      => 'true',
        'version'       => '1.2.0',

        'ttt' => 'ttt#1000-2000#true',
        'ddd' => 'ddd#1000-2000#true',
        'hhh' => 'hhh#1000-2000#true',

        'ch1' => 'ch1#1000-2000#false#1500',
        'ch2' => 'ch2#1000-2000#false#1500',
        'ch3' => 'ch3#2000-1000#true#1500',
        'ch4' => 'ch4#1000-2000#false#1500',
        'ch5' => 'ch5#1000-2000#false#1500',
        'ch6' => 'ch6#1000-2000#false#1500',

        'is_display'     => 0,
        'cooldown'       => 500,
        'bullet_channel' => 'C1',
    ];
}

/**
 * 4 - 推土机默认配置
 */
function bulldozerControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 5 - 翻斗车默认配置
 */
function dumpTruckControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 6 - 娃娃机默认配置
 */
function clawMachineControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 7 - 液压推土机默认配置
 */
function hydraulicBulldozerControlDefaults(): array
{
    return bulldozerControlDefaults();
}

/**
 * 8 - 液压翻斗默认配置
 */
function hydraulicDumpTruckControlDefaults(array $input = []): array
{
    return normalControlDefaults($input);
}

/**
 * 9 - 液压挖掘机默认配置
 */
function hydraulicExcavatorControlDefaults(): array
{
    return excavatorControlDefaults();
}

/**
 * 车辆类型总配置
 */
function getVehicleTypeMap(): array
{
    return [
        1 => [
            'label' => '汽车',
            'photo_url' => 'https://rcwulian.cn/img/m1282.jpg',
            'control_settings' => carControlDefaults(),
        ],

        2 => [
            'label' => '坦克',
            'photo_url' => 'https://rcwulian.cn/app/img/tl.png',
            'control_settings' => tankControlDefaults(),
        ],

        3 => [
            'label' => '挖掘机',
            'photo_url' => 'https://rcwulian.cn/img/wj01.jpg',
            'control_settings' => excavatorControlDefaults(),
        ],

        4 => [
            'label' => '推土机',
            'photo_url' => 'https://rcwulian.cn/app/img/583.jpg',
            'control_settings' => bulldozerControlDefaults(),
        ],

        5 => [
            'label' => '翻斗车',
            'photo_url' => 'https://rcwulian.cn/app/img/573.jpg',
            'control_settings' => dumpTruckControlDefaults(),
        ],

        6 => [
            'label' => '娃娃机',
            'photo_url' => 'https://rcwulian.cn/img/m1282.jpg',
            'control_settings' => clawMachineControlDefaults(),
        ],

        7 => [
            'label' => '液压推土机',
            'photo_url' => 'https://rcwulian.cn/app/imgv2/img/upload_1759200866_68db4662ee4ef.png',
            'control_settings' => hydraulicBulldozerControlDefaults(),
        ],

        8 => [
            'label' => '液压翻斗',
            'photo_url' => 'https://rcwulian.cn/img/latuche.png',
            'control_settings' => hydraulicDumpTruckControlDefaults(),
        ],

        9 => [
            'label' => '液压挖掘机',
            'photo_url' => 'https://rcwulian.cn/img/wj01.png',
            'control_settings' => hydraulicExcavatorControlDefaults(),
        ],
    ];
}

/**
 * 根据车辆类型获取默认图片
 */
function getVehiclePhotoUrlByType(int $carType): string
{
    $map = getVehicleTypeMap();

    if (isset($map[$carType]['photo_url']) && $map[$carType]['photo_url'] !== '') {
        return $map[$carType]['photo_url'];
    }

    return $map[1]['photo_url'];
}

/**
 * 构造 vehicle_control_settings 插入数据
 */
function buildVehicleControlSettingsData(
    string $serialNumber,
    int $carType,
    array $input = []
): array {
    $map = getVehicleTypeMap();
    $typeConfig = $map[$carType] ?? $map[1];

    $topic = $serialNumber;
    $controlSettings = $typeConfig['control_settings'] ?? null;

    // 有特殊完整配置的车型，例如液压推土机、液压挖掘机
    if (is_array($controlSettings) && !empty($controlSettings)) {
        return array_merge([
            'serial_number' => $serialNumber,
            'car_type'      => $carType,
            'topic'         => $topic,
        ], $controlSettings);
    }

    // 普通车型走简单配置
    return array_merge([
        'serial_number' => $serialNumber,
        'car_type'      => $carType,
        'topic'         => $topic,
    ], normalControlDefaults($input));
}