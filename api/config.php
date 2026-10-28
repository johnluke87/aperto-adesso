<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'aperto_adesso',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'debug' => true,
    'token_days' => 30,
    'cron_token' => 'aperto-adesso-cron-locale',
    'app_url' => 'http://localhost/aperto-adesso',
    'mail_from' => 'aperto-adesso@localhost',
    'donate_url' => 'https://ko-fi.com/johnluke87',
    'farmacie_turno_key' => '',
    'farmacie_csv_urls' => [
        'https://www.dati.salute.gov.it/imgs/C_17_dataset_5_download_itemDownload0_upFile.CSV',
        'https://www.dati.salute.gov.it/imgs/C_17_dataset_7_download_itemDownload0_upFile.CSV',
        'https://www.dati.salute.gov.it/imgs/C_17_dataset_7_download_itemDownload0_upFile.csv',
    ],
    'ps_json_url' => '',
];
