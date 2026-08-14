<?php

return [
    'automatic_fulfillment' => env('LESSON_RESOURCE_AUTO_FULFILL', true),
    'disk' => env('LESSON_RESOURCE_DISK', 'local'),
    'maximum_bytes' => 50 * 1024 * 1024,
    'providers' => [
        'usgs_maps' => [
            'license_name' => 'U.S. Public Domain',
            'license_url' => 'https://www.usgs.gov/faqs/are-usgs-reportspublications-copyrighted',
            'attribution' => 'Courtesy of the U.S. Geological Survey',
            'resources' => [
                'blank_map' => [
                    'title' => 'US 5E OUTLINE W/O STATE NAMES, US',
                    'product_number' => '101263',
                    'product_url' => 'https://store.usgs.gov/product/101263',
                    'download_url' => 'https://store.usgs.gov/assets/yimages/PDF/101263.pdf',
                    'filename' => 'usgs-blank-us-outline-map.pdf',
                ],
                'reference_map' => [
                    'title' => 'US 5D OUTLINE WITH STATE NAMES, US',
                    'product_number' => '101211',
                    'product_url' => 'https://store.usgs.gov/product/101211',
                    'download_url' => 'https://store.usgs.gov/assets/yimages/PDF/101211.pdf',
                    'filename' => 'usgs-labeled-us-reference-map.pdf',
                ],
            ],
        ],
        'census_state_geometry' => [
            'dataset_title' => '2025 Cartographic Boundary File — States, 1:20,000,000',
            'dataset_version' => 'January 1, 2025 vintage',
            'source_url' => 'https://www.census.gov/geographies/mapping-files/2025/geo/carto-boundary-file.html',
            'download_url' => 'https://www2.census.gov/geo/tiger/GENZ2025/kml/cb_2025_us_state_20m.zip',
            'attribution' => 'Source: U.S. Census Bureau',
            'license_name' => 'U.S. Government public data',
            'license_url' => 'https://www.census.gov/about/policies/open-gov.html',
            'filename' => 'census-2025-us-states-20m.geojson',
        ],
        'usgs_topography' => [
            'dataset_title' => 'CONUS Topography Map',
            'dataset_date' => '2026-04-27',
            'source_url' => 'https://www.usgs.gov/media/images/conus-topography-map',
            'download_url' => 'https://d9-wret.s3.us-west-2.amazonaws.com/assets/palladium/production/s3fs-public/media/images/CONUS-MT_2026_color_topography.jpg',
            'attribution' => 'Source: U.S. Geological Survey, Geomagnetism Program',
            'license_name' => 'U.S. Public Domain',
            'license_url' => 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits',
            'filename' => 'usgs-conus-topography-2026.jpg',
        ],
        'census_population_density' => [
            'dataset_title' => '2020 Population Density by State and District of Columbia',
            'source_url' => 'https://www.census.gov/data/tables/2020/dec/2020-apportionment-data.html',
            'population_url' => 'https://www2.census.gov/programs-surveys/decennial/2020/data/apportionment/apportionment.csv',
            'area_url' => 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_state_national.zip',
            'attribution' => 'Source: U.S. Census Bureau, 2020 Census Apportionment Results and 2024 Gazetteer Files',
            'license_name' => 'U.S. Government public data',
            'license_url' => 'https://www.census.gov/about/policies/open-gov.html',
            'filename' => 'census-2020-state-population-density.json',
        ],
        'science_lesson_foundation' => [
            'coastal_change' => [
                'source_url' => 'https://www.usgs.gov/media/before-after/section-big-hickory-beach-and-after-hurricane-ian',
                'before_page' => 'https://www.usgs.gov/media/images/big-hickory-beach-hurricane-ian',
                'after_page' => 'https://www.usgs.gov/media/images/section-big-hickory-beach-after-hurricane-ian',
                'before_url' => 'https://d9-wret.s3.us-west-2.amazonaws.com/assets/palladium/production/s3fs-public/media/images/Big%20Hickory%20Beach%20Before%20Hurricane%20Ian.jpg',
                'after_url' => 'https://d9-wret.s3.us-west-2.amazonaws.com/assets/palladium/production/s3fs-public/media/images/Big%20Hickory%20Beach%20After%20Hurricane%20Ian.jpg',
                'attribution' => 'Source: U.S. Geological Survey, Coastal and Marine Hazards and Resources Program',
                'license_name' => 'U.S. Public Domain',
                'license_url' => 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits',
            ],
        ],
    ],
];
