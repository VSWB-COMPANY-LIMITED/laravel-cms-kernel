<?php

/**
 * © 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Proprietary software developed and distributed by Visual Weber.
 * Use is permitted only under a valid license agreement.
 *
 * © 2026 CÔNG TY TNHH VISUAL WEBER. Bảo lưu mọi quyền.
 * Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
 */


use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

use Google\Client;
use Revolution\Google\Sheets\Facades\Sheets;
use Symfony\Component\HttpFoundation\Response;

#region Begin XML Pre-processing: Removes invalid XML
if (!function_exists('apps_recursive_sanitize_for_xml')) {
    /**
     * Recursively sanitize data structure for XML compatibility.
     *
     * Traverses arrays and objects recursively, applying XML sanitization
     * to all string values. Skips null, boolean, and numeric values.
     *
     * @param mixed $input Input data (passed by reference, modified in place)
     * @return void
     */
    function apps_recursive_sanitize_for_xml(&$input)
    {
        if (is_null($input) || is_bool($input) || is_numeric($input)) {
            return;
        }
        if (!is_array($input) && !is_object($input)) {
            $input = apps_sanitize_for_xml($input);
        } else {
            foreach ($input as &$value) {
                apps_recursive_sanitize_for_xml($value);
            }
        }
    }
}

if (!function_exists('apps_sanitize_for_xml')) {
    /**
     * Removes invalid XML characters from a string.
     *
     * Converts input to UTF-8 and removes characters that are not valid in XML.
     * Uses fast preg_replace, with fallback to slower character-by-character conversion.
     *
     * @see https://stackoverflow.com/questions/3466035/how-to-skip-invalid-characters-in-xml-file-using-php
     *
     * @param string $input Input string to sanitize
     * @return string Sanitized string with invalid XML characters removed
     */
    function apps_sanitize_for_xml($input)
    {
        // Convert input to UTF-8.
        $old_setting = ini_set('mbstring.substitute_character', '"none"');
        $input = mb_convert_encoding($input, 'UTF-8', 'auto');
        ini_set('mbstring.substitute_character', $old_setting);

        // Use fast preg_replace. If failure, use slower chr => int => chr conversion.
        $output = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $input);
        if (is_null($output)) {
            // Convert to ints.
            // Convert ints back into a string.
            $output = apps_ords_to_utfstring(apps_utfstring_to_ords($input), TRUE);
        }
        return $output;
    }
}
if (!function_exists('apps_ords_to_utfstring')) {
    /**
     * Given an array of ints representing Unicode chars, outputs a UTF-8 string.
     *
     * @param array $ords
     *   Array of integers representing Unicode characters.
     * @param bool $scrub_XML
     *   Set to TRUE to remove non valid XML characters.
     *
     * @return string
     *   UTF-8 String.
     */
    function apps_ords_to_utfstring($ords, $scrub_XML = FALSE)
    {
        $output = '';
        foreach ($ords as $ord) {
            // 0: Negative numbers.
            // 55296 - 57343: Surrogate Range.
            // 65279: BOM (byte order mark).
            // 1114111: Out of range.
            if (
                $ord < 0
                || ($ord >= 0xD800 && $ord <= 0xDFFF)
                || $ord == 0xFEFF
                || $ord > 0x10ffff
            ) {
                // Skip non valid UTF-8 values.
                continue;
            }
            // 9: Anything Below 9.
            // 11: Vertical Tab.
            // 12: Form Feed.
            // 14-31: Unprintable control codes.
            // 65534, 65535: Unicode noncharacters.
            elseif (
                $scrub_XML && ($ord < 0x9
                    || $ord == 0xB
                    || $ord == 0xC
                    || ($ord > 0xD && $ord < 0x20)
                    || $ord == 0xFFFE
                    || $ord == 0xFFFF)
            ) {
                // Skip non valid XML values.
                continue;
            } // 127: 1 Byte char.
            elseif ($ord <= 0x007f) {
                $output .= chr($ord);
                continue;
            } // 2047: 2 Byte char.
            elseif ($ord <= 0x07ff) {
                $output .= chr(0xc0 | ($ord >> 6));
                $output .= chr(0x80 | ($ord & 0x003f));
                continue;
            } // 65535: 3 Byte char.
            elseif ($ord <= 0xffff) {
                $output .= chr(0xe0 | ($ord >> 12));
                $output .= chr(0x80 | (($ord >> 6) & 0x003f));
                $output .= chr(0x80 | ($ord & 0x003f));
                continue;
            } // 1114111: 4 Byte char.
            elseif ($ord <= 0x10ffff) {
                $output .= chr(0xf0 | ($ord >> 18));
                $output .= chr(0x80 | (($ord >> 12) & 0x3f));
                $output .= chr(0x80 | (($ord >> 6) & 0x3f));
                $output .= chr(0x80 | ($ord & 0x3f));
                continue;
            }
        }
        return $output;
    }
}
if (!function_exists('apps_utfstring_to_ords')) {
    /**
     * Given a UTF-8 string, output an array of ordinal values.
     *
     * @param string $input
     *   UTF-8 string.
     * @param string $encoding
     *   Defaults to UTF-8.
     *
     * @return array
     *   Array of ordinal values representing the input string.
     */
    function apps_utfstring_to_ords($input, $encoding = 'UTF-8')
    {
        // Turn a string of unicode characters into UCS-4BE, which is a Unicode
        // encoding that stores each character as a 4 byte integer. This accounts for
        // the "UCS-4"; the "BE" prefix indicates that the integers are stored in
        // big-endian order. The reason for this encoding is that each character is a
        // fixed size, making iterating over the string simpler.
        $input = mb_convert_encoding($input, "UCS-4BE", $encoding);

        // Visit each unicode character.
        $ords = array();
        for ($i = 0; $i < mb_strlen($input, "UCS-4BE"); $i++) {
            // Now we have 4 bytes. Find their total numeric value.
            $s2 = mb_substr($input, $i, 1, "UCS-4BE");
            $val = unpack("N", $s2);
            $ords[] = $val[1];
        }
        return $ords;
    }
}

// $utf_8_range = range(0, 1114111);
// $output = apps_ords_to_utfstring($utf_8_range);
// $sanitized = apps_sanitize_for_xml($output);
#endregion

if (!function_exists('apps_get_worksheet_column_titles')) {
    /**
     * Extract column titles from the first row of a Google Sheets worksheet.
     *
     * Reads row 1 of the worksheet and creates a mapping of normalized keys
     * to original column titles. Handles duplicate column names by appending
     * sequence numbers (e.g., "column_2", "column_3").
     *
     * @param object $worksheet Google Sheets worksheet object
     * @return array Associative array mapping normalized keys to original column titles
     */
    function apps_get_worksheet_column_titles($worksheet)
    {
        $map = array();
        $already_in_map = array();
        $row_one_query = array(
            'min-row' => 1,
            'max-row' => 1
        );
        $cellfeed = $worksheet->getCellFeed($row_one_query);
        $entries = $cellfeed->getEntries();
        foreach ($entries as $cell) {
            $title = trim($cell->getContent());
            if ($title && $title[0] != '_') {
                $array_key = strtolower(preg_replace('/[^A-Z0-9_-]/i', '', $title));
                if (isset($already_in_map[$array_key]) && $already_in_map[$array_key]) {
                    // if identical column titles, google adds count to end
                    $seq = 2;
                    while ($already_in_map[$array_key . '_' . $seq]) {
                        $seq++;
                    }
                    $array_key .= "_{$seq}";
                }
                // mark this key as used as used.
                $already_in_map[$array_key] = true;
                $map[$array_key] = $title;
            } else {
                continue;
            }
        }
        return $map;
    }
}

if (!function_exists('apps_build_mapped_values')) {
    /**
     * Build mapped values from spreadsheet based on custom mappings.
     *
     * @param  array   $data                Input data, parsed into key => value array
     * @param  array   $mappings            User mapping configuration
     * @param  string  $logger              Log channel name
     * @param  string  $mode                Merge mode:
     *                                     - 'coalesce' (default): get first non-empty value
     *                                     - 'concat': concatenate all values (unique + preserve order)
     * @param  bool    $removeEmptyValues   If TRUE → remove keys with null or empty values
     * @return array                        Array of key => value after mapping
     *
     * @example
     *      // ===== INPUT DATA =====
     *      $data = [
     *          'diachi' => 'Số 03 Đồng Đen, P12, Quận Tân Bình',
     *          'noi_lam_viec' => '',
     *          'address' => 'TP. Hồ Chí Minh',
     *          'so_dien_thoai' => '0987654321'
     *      ];
     *
     *      // ===== MAPPINGS =====
     *      $mappings = [
     *          'address' => [
     *              ['key' => 'dia_chi', 'label' => 'Địa chỉ'],
     *              ['key' => 'noi_lam_viec', 'label' => 'Nơi làm việc'],
     *          ],
     *          'phone' => [
     *              ['key' => 'so_dien_thoai', 'label' => 'SĐT']
     *          ]
     *      ];
     *
     *      // ===== USAGE =====
     *      $result = apps_build_mapped_values($data, $mappings, 'daily', 'coalesce');
     *
     *      // ===== OUTPUT (mode = 'coalesce') =====
     *      [
     *          'address' => 'Số 03 Đồng Đen, P12, Quận Tân Bình', // lấy giá trị ĐẦU TIÊN không rỗng
     *          'phone' => '0987654321'
     *      ]
     *
     *      // ===== OUTPUT (mode = 'concat') =====
     *      [
     *          'address' => 'Số 03 Đồng Đen, P12, Quận Tân Bình TP. Hồ Chí Minh',
     *          'phone' => '0987654321'
     *      ]
     */
    function apps_build_mapped_values(
        array $data,
        array $mappings,
        string $logger = 'daily',
        string $mode = 'coalesce',
        bool $removeEmptyValues = true
    ): array {
        Log::channel($logger)->info('hub_step fields mapping to spreadsheet', (array) $mappings);

        $values_mappings = [];

        foreach ($mappings as $mapping_key => $_mappings) {
            /* reset value before each loop to avoid data contamination */
            $values_mappings[$mapping_key] = null;
            $bucket = [];
            Log::channel($logger)->info('mapping_key', (array) $mapping_key);

            foreach ((array) $_mappings as $__mapping) {
                if (!isset($__mapping['key']))
                    continue;

                /* split by '|' FIRST, then slug (fix old order bug) */
                /**
                 * note: spreadsheet column names do not accept _ and any characters outside alphabet
                 * example: "1009385287145182|phone_number" or "phone_number" or "parent_phone:"
                 * split by '|' FIRST, then slug (fix old order bug)
                 */
                $parts = explode('|', (string) $__mapping['key'], 2);
                $rawKey = $parts[1] ?? $parts[0];

                /* chuẩn hóa key: ascii + slug + bỏ separator */
                $__mapping_key = Str::slug(Str::ascii($rawKey), '');
                Log::channel($logger)->info('__mapping_key slug', (array) $__mapping_key);

                /* get value from input */
                $raw = Arr::get($data, $__mapping_key);
                Log::channel($logger)->info('__raw value', (array) $raw);

                /* normalize value: trim, remove empty, support arrays */
                if (is_array($raw)) {
                    $raw = implode(' ', array_map('trim', array_filter($raw, fn($v) => !blank($v))));
                } else {
                    $raw = trim((string) $raw);
                }

                if ($raw !== '') {
                    $bucket[] = $raw;
                }
            }

            /* ===== bắt đầu gộp ===== */
            if (!empty($bucket)) {
                if ($mode === 'concat') {
                    /* unique theo dạng chuẩn hóa (lower + gộp khoảng trắng) */
                    $norm = [];
                    foreach ($bucket as $b) {
                        $k = strtolower(preg_replace('/\s+/', ' ', $b));
                        $norm[$k] = $b;
                    }
                    $values_mappings[$mapping_key] = implode(' ', array_values($norm));
                } else {
                    /* mặc định: lấy giá trị ĐẦU TIÊN không rỗng */
                    $values_mappings[$mapping_key] = $bucket[0];
                }
            } else {
                Log::channel($logger)->warning('hub_step fields mapping data is empty');
            }
        }

        /* Lọc bỏ các giá trị null hoặc chuỗi rỗng nếu người dùng yêu cầu */
        if ($removeEmptyValues) {
            $values_mappings = array_filter(
                $values_mappings,
                fn($value) => !is_null($value) && $value !== ''
            );
        }

        Log::channel($logger)->info('hub_step fields mapping data to spreadsheet', $values_mappings);

        return $values_mappings;
    }
}
if (!function_exists('apps_google_sheet')) {
    /**
     * Append data to a Google Sheets spreadsheet.
     *
     * Authenticates with Google Sheets API (OAuth2 or Service Account),
     * maps data to spreadsheet columns, and appends a new row.
     * Supports custom field mappings and header caching for performance.
     *
     * @param array $spreadsheetData Data to append (key-value pairs)
     * @param array $spreadsheet Spreadsheet configuration:
     *                          - spreadsheet.id: Google Sheets ID
     *                          - spreadsheet.name: Spreadsheet name
     *                          - sheet.id: Sheet ID (numeric)
     *                          - sheet.name: Sheet name
     * @param string $credentialsType Authentication type: 'oauth2' or 'service_account'
     * @param string|null $credentialsFile Path to service account JSON file (for service_account)
     * @param array|null $connection Connection data containing OAuth tokens (for oauth2)
     * @param string $logger Log channel name
     * @param array $mappings Custom field mappings for spreadsheet columns
     * @param bool $with_keys If true, match data keys to spreadsheet headers
     * @param bool $cache_headers Enable caching of spreadsheet headers
     * @param int $cache_ttl Cache TTL in seconds (default: 300)
     * @param bool $cache_force_refresh Force refresh of cached headers
     * @return string JSON-encoded response with success/error status
     * @throws \Throwable
     */
    function apps_google_sheet(
        $spreadsheetData,
        $spreadsheet,
        $credentialsType = 'oauth2',
        $credentialsFile = '',
        $connection = null,
        $logger = 'daily',
        $mappings = [],
        $with_keys = true,
        $cache_headers = true, // allow enable/disable cache
        $cache_ttl = 300,      // default TTL: 300s (5 minutes)
        $cache_force_refresh = false // allow bypass cache
    ) {
        try {
            Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");
            Log::channel($logger)->info(__FUNCTION__ . ": spreadsheet variables", (array) $spreadsheet);

            #region validate required spreadsheet config
            if (isset($spreadsheetData['status']) && $spreadsheetData['status'])
                unset($spreadsheetData['status']);

            if (
                !Arr::has($spreadsheet, 'spreadsheet.id')
                || blank(Arr::get($spreadsheet, 'spreadsheet.id'))
                || !is_string(Arr::get($spreadsheet, 'spreadsheet.id'))
            ) {
                throw new Exception("Spreadsheet ID is required and must be a valid string");
            }

            if (!array_key_exists('id', $spreadsheet['sheet'])) {
                throw new Exception("Sheet ID is required.");
            }

            if (!Arr::has($spreadsheet, 'sheet.name') || blank(Arr::get($spreadsheet, 'sheet.name'))) {
                throw new Exception("Sheet name is required.");
            }
            // Log::channel($logger)->info('[GSheet::Spreadsheet Info]', [
            //     'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
            //     'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),
            //     'sheet_name' => Arr::get($spreadsheet, 'sheet.name'),
            // ]);

            #endregion

            #region Spreadsheet Key process
            $spreadsheetData = collect($spreadsheetData)
                ->mapWithKeys(fn($value, $key) => [Str::slug(Str::ascii($key), '') => $value])
                ->toArray();
            #endregion

            #region Spreadsheet authentication
            try {
                if ($credentialsType == 'oauth2') { // using laravel style
                    #region Use a "OAuth2 Account" to connection and spreadsheet process data
                    // Log::channel($logger)->info("Starting with credential '{$credentialsType}'");

                    $connection = $connection['connection'];
                    // Log::channel($logger)->info("Connection captured", $connection);
                    $accessToken = [
                        'access_token' => $connection['provider_exchange_token'],
                        'refresh_token' => $connection['provider_exchange_refresh_token'],
                    ]; // $accessToken = json_decode(file_get_contents(storage_path('app/google-oauth2-tokens.json')), true);
                    #endregion
                } else { // using non-laravel style
                    #region Setup Google Client & Service Account
                    $client = new Client();
                    $client->setApplicationName(env('APP_NAME'));
                    $client->setScopes([
                        'https://www.googleapis.com/auth/spreadsheets',
                        'https://www.googleapis.com/auth/drive.file',
                        // 'https://www.googleapis.com/auth/drive', // (only enable temporarily when token needed) application needs security certificates according to google standards (quite difficult), See, edit, create, and delete all of your Google Drive files
                        // 'https://www.googleapis.com/auth/adwords,
                        // 'https://www.googleapis.com/auth/script.projects'
                    ]);
                    $client->setAccessType('offline');
                    #endregion

                    #region Use a "Service Account" to connection and spreadsheet process data
                    // Log::channel($logger)->info("Starting with credential '{$credentialsType}'");

                    #region Setup Google Credentials
                    if (!$credentialsFile) {
                        $configuredPath = (string) config('google.service.file', config_path('google-service-account-credentials.json'));
                        $resolvedConfiguredPath = str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
                            ? $configuredPath
                            : config_path($configuredPath);

                        $candidateFiles = [
                            storage_path('app/google-service-account-credentials.json'),
                            $resolvedConfiguredPath,
                            config_path('google-service-account-credentials.json'),
                        ];

                        foreach ($candidateFiles as $candidateFile) {
                            if (is_file($candidateFile) && is_readable($candidateFile)) {
                                $credentialsFile = $candidateFile;
                                break;
                            }
                        }
                    }

                    if (!$credentialsFile || !is_file($credentialsFile) || !is_readable($credentialsFile)) {
                        throw new Exception('Google service account credentials file is missing or not readable');
                    }

                    $client->setAuthConfig($credentialsFile); // use environment variable: putenv("GOOGLE_APPLICATION_CREDENTIALS=$credentialsFile"); $client->useApplicationDefaultCredentials();
                    #endregion

                    $tokenPath = storage_path('app/google-service-account-tokens.json');
                    if (file_exists($tokenPath)) {
                        $accessToken = json_decode(file_get_contents($tokenPath), true);
                        $client->setAccessToken($accessToken);
                    }
                    if ($client->isAccessTokenExpired()) {
                        Log::channel($logger)->warning(__FUNCTION__, (array) "Token is expired, it will be automatically renew rightnow");
                        if ($client->getRefreshToken()) {
                            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                        } else {
                            // Exchange authorization code for an access token.
                            $accessToken = $client->fetchAccessTokenWithAssertion(); //  should store to file
                            $client->setAccessToken($accessToken);
                            // Check to see if there was an error.
                            if (array_key_exists('error', $accessToken))
                                throw new Exception(join(', ', $accessToken));
                        }
                        if (!file_exists(dirname($tokenPath))) // save token after renewing
                            mkdir(dirname($tokenPath), 0700, true);

                        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
                    }
                    #endregion
                }
            } catch (\Throwable $th) {
                Log::channel($logger)->error(__FUNCTION__ . ": " . $th->getMessage());
                throw $th; // throw errors
            }
            #endregion

            #region Spreadsheet process data
            $values = $values_mappings = [];
            // $spreadsheet['spreadsheet']['id'] = "1zfwtB8vh5RKp9va11F3XHRCC30Zpzbu_1I-RLwS6ugU";
            // $spreadsheet['sheet']['name'] = "LIST DATA";

            if ($with_keys) { // When providing an associative array, values get matched up to the headers in the provided sheet

                #region setup caching headers

                // $rows = Sheets::setAccessToken($accessToken)
                //     ->spreadsheet($spreadsheet['spreadsheet']['id'])
                //     ->sheetById($spreadsheet['sheet']['id'])->get();
                // $headers = $rows->pull(0); // $values = Sheets::collection(header: $header, rows: $rows)->toArray();

                #region generate safe cache key
                $cacheKey = 'gsheet_headers_' . md5(json_encode([
                    'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
                    'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),      // still keep 0 if present, because the first sheet in spreadsheet may have ID value = 0
                    'sheet_name' => Arr::get($spreadsheet, 'sheet.name'),
                ]));

                // Log::channel($logger)->info('[GSheet::CacheKey Usage]', [
                //     'cache_key' => $cacheKey,
                //     'spreadsheet_id' => $spreadsheet['spreadsheet']['id'],
                //     'sheet_id' => $spreadsheet['sheet']['id'],
                //     'sheet_name' => $spreadsheet['sheet']['name'],
                //     'form_id' => $spreadsheetData['formid'] ?? 'n/a',
                //     'form_name' => $spreadsheetData['formname'] ?? 'n/a',
                // ]);
                #endregion

                if ($cache_headers && !$cache_force_refresh) {

                    // if (Cache::has($cacheKey)) Log::channel($logger)->info('[GSheet Cache::Hit]', ['cache_key' => $cacheKey]);
                    // else Log::channel($logger)->info('[GSheet Cache::Miss]', ['cache_key' => $cacheKey]);

                    /**
                     * if cache HIT => remember will automatically return cached data if available, otherwise will run callback and store data in cache
                     */
                    $headers = Cache::remember($cacheKey, $cache_ttl, function () use ($accessToken, $spreadsheet, $logger, $cacheKey, $cache_ttl) {
                        // Log::channel($logger)->info('[GSheet Cache::Rebuilding headers]', [
                        //     'spreadsheet_id' => $spreadsheet['spreadsheet']['id'],
                        //     'sheet_id' => $spreadsheet['sheet']['id'] ?? '',
                        //     'sheet_name' => $spreadsheet['sheet']['name'] ?? '',
                        //     'cache_key' => $cacheKey,
                        // ]);

                        $fetched = Sheets::setAccessToken($accessToken)
                            ->spreadsheet($spreadsheet['spreadsheet']['id']) // this is the point that changes context state, be careful if using implicit logic append data
                            ->sheetById($spreadsheet['sheet']['id'])
                            ->get()
                            ->pull(0);

                        return is_array($fetched) ? $fetched : collect($fetched)->toArray();
                    });

                    #region debug cache
                    // apps_cache_debug($cacheKey, $logger);
                    // Log::channel($logger)->info('[GSheet Cache::Returned Headers]', [
                    //     'source' => Cache::has($cacheKey) ? 'cache-hit' : 'callback',
                    //     'cache_key' => $cacheKey,
                    //     'headers' => $headers,
                    // ]);
                    #endregion
                } else {
                    // Log::channel($logger)->info('[GSheet Cache::Bypass or Forced Refresh]', [
                    //     'cache_force_refresh' => $cache_force_refresh,
                    //     'enabled' => $cache_headers,
                    //     'cache_key' => $cacheKey,
                    //     'ttl' => $cache_ttl,
                    // ]);

                    $headers = Sheets::setAccessToken($accessToken)
                        ->spreadsheet($spreadsheet['spreadsheet']['id']) // this is the point that changes context state, be careful if using implicit logic append data
                        ->sheetById($spreadsheet['sheet']['id'])
                        ->get()
                        ->pull(0);

                    $headers = is_array($headers) ? $headers : collect($headers)->toArray();

                    if ($cache_headers) {
                        Cache::put($cacheKey, $headers, $cache_ttl);
                        // Log::channel($logger)->info('[GSheet Cache::Store]', [
                        //     'cache_key' => $cacheKey,
                        //     'ttl' => $cache_ttl,
                        // ]);
                    }
                }
                #endregion

                #region mapping sample data
                // ' // sample data
                // "Phone": [
                //     {"id": "114974625017517","key": "1367111817480640|số_điện_thoại","label": "Phone number","form_id": "1367111817480640"},
                //     {"id": "1353924855537837","key": "1009385287145182|phone_number","label": "Phone number","form_id": "1009385287145182"}]';
                #endregion

                if (count($mappings)) { // Custom headers mappings w/ "field key" of ads-form vs "column" name of spreadsheet.
                    Log::channel($logger)->info('hub_step fields mapping to spreadsheet', (array) $mappings);

                    #region before improve
                    // foreach ($mappings as $mapping_key => $_mappings) {
                    //     $values_mappings[$mapping_key] = null;
                    //     Log::channel($logger)->info('mapping_key', (array) $mapping_key);
                    //     if (count($_mappings)) {
                    //         foreach ($_mappings as $__mapping) {
                    //             /**
                    //              * chú ý: tên cột của spreadsheet không chấp nhận dấu _ và bất kỳ ký tự ngoài các chữ alphebet
                    //              * eg: "1009385287145182|số_điện_thoại" or "số_điện_thoại or sđt_của_cha_mẹ:"
                    //              */
                    //             $__mapping_key = Str::slug($__mapping['key'], '');
                    //             $__mapping_key = isset(explode("|", $__mapping_key)[1]) ? explode("|", $__mapping_key)[1] : $__mapping_key;
                    //             Log::channel($logger)->info('__mapping_key slug', (array) $__mapping_key);

                    //             if (
                    //                 (isset($spreadsheetData['providerformid']) && $spreadsheetData['providerformid']) ||
                    //                 (isset($spreadsheetData['provider_form_id']) && $spreadsheetData['provider_form_id'])
                    //             ) {
                    //                 #code
                    //             }

                    //             $svalue = Arr::get($spreadsheetData, $__mapping_key);
                    //             if ($svalue && $values_mappings[$mapping_key] != $svalue)
                    //                 $values_mappings[$mapping_key] .= is_array($svalue) ? json_encode($svalue) : "{$svalue} ";
                    //         }
                    //     }
                    // }
                    // $values_mappings = array_filter($values_mappings, fn($value) => !is_null($value) && $value !== '');
                    #endregion

                    #region improved mapping
                    $values_mappings = apps_build_mapped_values(
                        $spreadsheetData, // Dữ liệu input
                        $mappings,        // Config mappings
                        $logger,          // Log channel
                        'concat',         // Hoặc 'coalesce'
                        true              // Lọc giá trị rỗng
                    );
                    #endregion

                    Log::channel($logger)->info('hub_step fields mapping data to spreadsheet', $values_mappings);
                }

                foreach ($headers as $header) { // Default headers mappings
                    if ($svalue = Arr::get($spreadsheetData, Str::slug($header, ''))) {
                        $values[$header] = is_array($svalue) ? json_encode($svalue) : $svalue;
                    }
                }
                $values = array_filter($values, fn($value) => !is_null($value) && $value !== '');
                // Log::channel($logger)->info('Spreadsheet Data with default headers', $values);

                $values = array_merge($values, $values_mappings);
            } else {
                $values = array_values($spreadsheetData);
            }

            // Log::channel($logger)->info('Spreadsheet values Data', $values);
            // Log::channel($logger)->info('Writing to spreadsheet', [
            //     'spreadsheet_id' => $spreadsheet['spreadsheet']['id'],
            //     'sheet_id' => $spreadsheet['sheet']['id'],
            //     'sheet_name' => $spreadsheet['sheet']['name'],
            // ]);

            // Log::channel($logger)->info('[GSheet Append::Started]', [
            //     'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
            //     'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),
            //     'values' => $values,
            // ]);

            // Should explicitly specify ->spreadsheet 'spreadsheet.id', avoid reuse from previous stage,
            // Be careful with incorrect spreadsheet insertion if context stage "spreadsheet.id" is modified somewhere
            $result = Sheets::setAccessToken($accessToken)
                ->spreadsheet(Arr::get($spreadsheet, 'spreadsheet.id'))
                ->sheetById(Arr::get($spreadsheet, 'sheet.id'))
                ->append([$values]);

            // Log::channel($logger)->info('[GSheet Append::Done]', [
            //     'result' => $result->toSimpleObject() ?? [],
            // ]);
            #endregion Spreadsheet process data

            return json_encode([
                "error" => false,
                'code' => Response::HTTP_OK,
                'statusCode' => Response::HTTP_OK,
                "data" => [
                    'workbookId' => Arr::get($spreadsheet, 'spreadsheet.id', null),
                    'sheetId' => Arr::get($spreadsheet, 'sheet.id', null),
                    'sheetName' => Arr::get($spreadsheet, 'sheet.name', null),
                    ...(array) $result->toSimpleObject()
                ],
                "message" => "Request has been successfully processed"
            ]);
        } catch (\Throwable $th) {
            Log::channel($logger)->error(__FUNCTION__, (array) $th->getMessage());
            Log::channel($logger)->error(__FUNCTION__, (array) $th->getTraceAsString());
            return json_encode([
                "error" => true,
                'code' => $th->getCode(),
                'statusCode' => $th->getCode(),
                "data" => [
                    'workbookId' => Arr::get($spreadsheet, 'spreadsheet.id', null),
                    'sheetId' => Arr::get($spreadsheet, 'sheet.id', null),
                    'sheetName' => Arr::get($spreadsheet, 'sheet.name', null)
                ],
                "message" => $th->getMessage()
            ]);
        }

        Log::channel($logger)->warning(__FUNCTION__ . '::Unexpected flow fallback');
        return json_encode([
            "error" => true,
            'code' => Response::HTTP_BAD_REQUEST,
            'statusCode' => Response::HTTP_BAD_REQUEST,
            "data" => [
                'workbookId' => Arr::get($spreadsheet, 'spreadsheet.id', null),
                'sheetId' => Arr::get($spreadsheet, 'sheet.name', null),
                'sheetName' => Arr::get($spreadsheet, 'sheet.name', null)
            ],
            "message" => "An error occurred"
        ]);
    }
}

/**
 * Update a specific row in Google Sheets based on log data from a previous append operation.
 *
 * Extracts spreadsheet ID, sheet name, and row index from the log data,
 * then updates the corresponding row with new data.
 *
 * @param array $rowData New row data to update (array of values)
 * @param array $log Log data from previous apps_google_sheet() call containing:
 *                   - data.spreadsheetId: Google Sheets ID
 *                   - data.sheetName: Sheet name
 *                   - data.updates.updatedRange: Range string (e.g., "Raw!A2558:J2558")
 * @return array Result array with 'error', 'message', and 'data' keys
 */
if (!function_exists('apps_google_sheet_update_by_log')) {
    function apps_google_sheet_update_by_log(array $rowData, array $log): array
    {
        $data = data_get($log, 'data');
        $sheetName = data_get($data, 'sheetName');
        $spreadsheetId = data_get($data, 'spreadsheetId');
        $updatedRange = data_get($data, 'updates.updatedRange'); // Raw!A2558:J2558

        if (!$spreadsheetId || !$sheetName || !$updatedRange) {
            return [
                'error' => true,
                'message' => 'Missing spreadsheetId or updatedRange in log data',
            ];
        }

        // Identify the row to update
        preg_match('/!([A-Z]+)(\d+):/', $updatedRange, $matches);
        $startCol = $matches[1] ?? 'A';
        $rowIndex = $matches[2] ?? null;

        if (!$rowIndex) {
            return [
                'error' => true,
                'message' => 'Could not extract row index from updatedRange',
            ];
        }

        // Calculate number of columns (A, B, C...) → number of elements in $rowData
        $endColIndex = count($rowData) - 1;
        $endCol = apps_num2alpha($endColIndex); // Example: 0 → A, 9 → J

        $targetRange = "{$sheetName}!{$startCol}{$rowIndex}:{$endCol}{$rowIndex}";

        try {
            $response = Sheets::spreadsheet($spreadsheetId)
                ->range($targetRange)
                ->update([$rowData]); // Google Sheets API expects a 2D array

            return [
                'error' => false,
                'message' => 'Row updated successfully',
                'provider' => 'leadgen_notification_spreadsheet',
                'data' => [
                    'spreadsheetId' => $spreadsheetId,
                    'sheetName' => $sheetName,
                    'updatedRange' => $targetRange,
                    'updatedValues' => $rowData,
                    'response' => $response,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => 'Update failed: ' . $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('apps_phone_convert')) {
    /**
     * Convert phone number to Vietnamese standard format.
     *
     * Normalizes phone numbers by:
     * - Removing all non-numeric characters
     * - Converting international format (84xxxxxxxxx) to local (0xxxxxxxxx)
     * - Adding leading zero for 9-digit numbers
     *
     * @param string|null $phonenumber Phone number in any format
     * @return string|false Normalized phone number (0xxxxxxxxx) or false on error
     */
    function apps_phone_convert($phonenumber)
    {
        if (!$phonenumber)
            return false;
        try {
            $phonenumber = preg_replace("/[^0-9.]/", "", $phonenumber); // get number only, eg: p:+84982311866
            if (mb_strlen($phonenumber) > 10 && substr($phonenumber, 0, 2) == '84') { // only for vietnam, eg: 84982311866
                $phonenumber = "0" . substr($phonenumber, 2);
            }

            if (mb_strlen($phonenumber) == 9) { // only for vietnam, eg 982311866
                $phonenumber = "0" . $phonenumber;
            }

            return $phonenumber;
        } catch (\Throwable $th) {
        }

        return false;
    }
}
if (!function_exists('apps_currency_exchange')) {
    /**
     * Get USD to VND exchange rate from VN App Mob API.
     *
     * Fetches the current USD sell price from Vietcombank exchange rate API.
     * Returns a default value of 24500 if the API call fails.
     *
     * @return float USD sell price in VND (default: 24500)
     */
    function apps_currency_exchange()
    {
        try {
            $responseAccessToken = Http::get('https://vapi.vnappmob.com/api/request_api_key?scope=exchange_rate');
            $accessToken = trim(Arr::get($responseAccessToken->json(), 'results'));

            $responseExchangeRate = Http::withHeaders([
                'Authorization' => "Bearer $accessToken"
            ])
                ->get('https://vapi.vnappmob.com/api/v2/exchange_rate/vcb');
            $result = Arr::get($responseExchangeRate->json(), 'results');
            $dollarSellPrice = Arr::get(Arr::first(array_filter($result, function ($item) {
                return Arr::get($item, 'currency') == 'USD';
            })), 'sell');

            return $dollarSellPrice ?? 24500;
        } catch (\Throwable $th) {
            return 24500;
        }
    }
}

if (!function_exists('apps_log_channel')) {
    /**
     * Create a dynamic logger channel by name + optional author_id
     *
     * @param string $logger
     * @param int|null $author_id
     * @return string
     */
    function apps_log_channel(string $logger, $author_id = null): string
    {
        try {
            $loggerName = ($author_id ? "member-{$author_id}-" : '') . $logger;

            // If channel doesn't exist yet, configure it
            if (!Config::get("logging.channels.{$loggerName}")) {
                Config::set("logging.channels.{$loggerName}", [
                    'driver' => 'daily',
                    'path' => storage_path("logs/{$loggerName}.log"),
                    'level' => env('APP_LOG_LEVEL', 'debug'),
                    'days' => 14,
                    'lazy' => true, // Optimize disk I/O, should not enable if important financial-related logs need to be written immediately.
                ]);
            }

            return $loggerName;
        } catch (\Throwable $th) {
            Log::error('apps_log_channel exception', [
                'message' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
            return config('logging.default');
        }
    }
}

if (!function_exists('apps_log')) {
    /**
     * Smart Logging Helper: Automatically registers log channels at runtime if they are not defined.
     * Purpose: Ensures logging remains robust and fail-safe, particularly in background/queue processes
     * where dynamic configurations from the web request might be lost.
     *
     * @param string $logger Channel name (e.g., 'daily', 'spreadsheet', 'member-123-custom')
     * @param int|null $author_id Optional author ID for member-prefixed logs
     * @return \Illuminate\Log\Logger
     */
    function apps_log(string $logger = 'daily', $author_id = null)
    {
        return Log::channel(apps_log_channel($logger, $author_id));
    }
}

if (!function_exists('apps_log_write')) {
    /**
     * Advanced logging for the application.
     *
     * Supports regular logs + structured block logs:
     *   start → step → success/fail
     *
     * Example 1: Regular log (like old version)
     * ----------------------------------------------------
     * apps_log_write('info', 'Something happened', 'facebook-webhook');
     *
     * Example 2: Log block with START / SUCCESS / FAIL
     * ----------------------------------------------------
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'start');
     * apps_log_write('info', 'Analyzing data', 'facebook-webhook', 'step');
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'success');
     *
     * Example 3: Log block with error
     * ----------------------------------------------------
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'start');
     * try {
     *     throw new \Exception('JSON parsing error');
     * } catch (\Throwable $e) {
     *     apps_log_write('error', 'Webhook@handle', 'facebook-webhook', 'fail', $e);
     * }
     *
     * @param string $level       Log level: info|debug|warning|error
     * @param string $message     Content or block name (if block log)
     * @param string|null $channel Log channel, defaults to config logging.default
     * @param string $mode        normal | start | success | fail | step
     * @param \Throwable|null $exception Exception if any (fail mode)
     *
     * @return void
     */
    function apps_log_write(
        string $level,
        string $message,
        ?string $channel = null,
        string $mode = 'normal',
        ?\Throwable $exception = null
    ): void {
        static $blockStartTimes = [];

        $channelName = $channel ? apps_log_channel($channel) : config('logging.default');

        // When block starts → save timestamp
        if ($mode === 'start') {
            $blockStartTimes[$message] = microtime(true);
            Log::channel($channelName)->{$level}("📥 [BLOCK START] {$message}");
            return;
        }

        // When block succeeds → calculate duration
        if ($mode === 'success') {
            $duration = isset($blockStartTimes[$message])
                ? round((microtime(true) - $blockStartTimes[$message]) * 1000, 2)
                : 0;

            unset($blockStartTimes[$message]);
            Log::channel($channelName)->{$level}("✅ [BLOCK END] {$message} (duration={$duration}ms)");
            return;
        }

        // When block fails → log fail + duration + exception
        if ($mode === 'fail') {
            $duration = isset($blockStartTimes[$message])
                ? round((microtime(true) - $blockStartTimes[$message]) * 1000, 2)
                : 0;

            unset($blockStartTimes[$message]);

            $errorMsg = $exception
                ? $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine()
                : 'Unknown error';

            Log::channel($channelName)->error("❌ [BLOCK END] {$message} failed (duration={$duration}ms) | {$errorMsg}");
            return;
        }

        // Log each step in block → don't reset timestamp
        if ($mode === 'step') {
            $elapsed = isset($blockStartTimes[$message])
                ? round((microtime(true) - $blockStartTimes[$message]) * 1000, 2)
                : 0;

            Log::channel($channelName)->{$level}("🔹 [STEP +{$elapsed}ms] {$message}");
            return;
        }

        // Default → regular log like old version
        Log::channel($channelName)->{$level}($message);
    }
}

if (!function_exists('apps_log_stringify')) {
    /**
     * Convert any value to a safe string for logging
     *
     * Supports conversion of data types: string, scalar (int, float, bool), null,
     * array, object (with/without __toString), and unloggable types.
     *
     * @param mixed $value Value to convert
     * @return string Safe string for logging
     *
     * @example
     * // String - return as is
     * apps_log_stringify('Hello World'); // 'Hello World'
     *
     * // Scalar values - convert to string
     * apps_log_stringify(123); // '123'
     * apps_log_stringify(45.67); // '45.67'
     * apps_log_stringify(true); // '1'
     * apps_log_stringify(null); // ''
     *
     * // Array - convert to JSON
     * apps_log_stringify(['name' => 'John', 'age' => 30]);
     * // '{"name":"John","age":30}'
     *
     * // Object with __toString
     * $date = new \DateTime('2024-01-01');
     * apps_log_stringify($date); // '2024-01-01 00:00:00'
     *
     * // Object without __toString - convert to JSON or class name
     * $user = new stdClass();
     * $user->name = 'John';
     * apps_log_stringify($user); // '{"name":"John"}' or 'Object(stdClass)'
     *
     * // Use with Log
     * Log::channel('daily')->info('User data: ' . apps_log_stringify($userData));
     */
    function apps_log_stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || is_null($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return apps_json_encode($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            try {
                return apps_json_encode($value) ?: 'Object(' . get_class($value) . ')';
            } catch (\Throwable) {
                return 'Object(' . get_class($value) . ')';
            }
        }

        return '[Unloggable type]';
    }
}

if (!function_exists('apps_pull_login')) {
    /**
     * Authenticate with Pull API using email and password.
     *
     * Makes a login request to the Pull API endpoint and returns
     * the authentication result. Note: Consider rate limiting for production use.
     *
     * @param string $email User email address
     * @param string $password User password
     * @param string $method HTTP method (default: 'POST')
     * @param string $logger Log channel name
     * @return array Authentication result with 'error' and 'data' keys
     */
    function apps_pull_login($email, $password, $method = 'POST', $logger = 'daily'): array
    {
        ### @@ quốc em xử lý giùm anh trường hợp gọi login nhiều quá, đơ máy chủ nhé. khi đó giá trị trả ra bị null
        ### @@ em xem hình như lrv có limit được từ routes đấy, hoặc dùng token, refresh token như các social hay làm
        try {
            $result = (function ($method, $url, $data, $logger = 'daily') {
                $curl = curl_init();
                switch ($method):
                    case "POST":
                        $headers = ["Content-Type:application/json; charset=utf-8"];
                        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($curl, CURLOPT_HEADER, 0); // DO NOT RETURN HTTP HEADERS
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($curl, CURLOPT_POST, 1);
                        if ($data)
                            curl_setopt($curl, CURLOPT_POSTFIELDS, collect($data)->toJson());
                        break;
                    case "PUT":
                        curl_setopt($curl, CURLOPT_PUT, 1);
                        break;
                    default:
                        if ($data)
                            $url = sprintf("%s?%s", $url, http_build_query($data));
                        break;
                endswitch;

                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // RETURN THE CONTENTS OF THE CALL

                $result = curl_exec($curl);

                if (!curl_errno($curl)) {
                    $info = curl_getinfo($curl);

                    if ($info['http_code'] == 200) {
                    } elseif ($info['http_code'] == 415) {
                    }
                } else {
                    $result = json_encode([
                        "error" => false,
                        "data" => [
                            "code" => "CHALLENGE_FAILED",
                            "message" => curl_error($curl)
                        ]
                    ]);
                }

                curl_close($curl);
                return $result;
            })(
                $method,
                env('APP_URL', 'https://apis.pull.vn') . "/api/v1/login",
                [
                    'email' => $email,
                    'password' => $password
                ],
                $logger
            ); // return json string

            $result = json_decode($result, true);
        } catch (\Throwable $th) {
            $result = [
                "error" => false,
                "data" => [
                    "code" => $th->getCode(),
                    "message" => $th->getMessage()
                ]
            ];
            Log::channel($logger)->error('Exception', $result);
        }

        return $result;
    }
}

if (!function_exists('apps_vtiger_login')) {
    /**
     * Authenticate with Vtiger CRM using challenge-response mechanism.
     *
     * Implements Vtiger's two-step authentication:
     * 1. Get challenge token from server
     * 2. Login with MD5 hash of (challenge_token + accessKey)
     *
     * @param string $username Vtiger username
     * @param string $accessKey Vtiger access key
     * @param string $method HTTP method (default: 'POST')
     * @param string $logger Log channel name
     * @return array Authentication result with 'error' and 'data' keys
     */
    function apps_vtiger_login($username, $accessKey, $method = 'POST', $logger = 'daily')
    {
        // Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");
        try {
            $result = (function ($method, $url, $data, $logger) {
                ///// GET CHALLENGE
                $curl = curl_init();
                $options = array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_URL => $url . '?operation=getchallenge&username=' . $data['username'],

                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_FOLLOWLOCATION => false,
                );
                curl_setopt_array($curl, $options);
                $challenge = curl_exec($curl);
                $challenge = json_decode($challenge, true);

                // Log::channel($logger)->info($challenge);

                curl_close($curl);

                if (
                    $challenge['success']
                    && isset($challenge['result']['token'])
                ) {
                    ///// POST LOGIN
                    $curl = curl_init();
                    switch ($method) {
                        case "POST":
                            $headers = array(
                                "application/x-www-form-urlencoded; charset=utf-8", // 'Authorization:Bearer ' . $accessToken, // "Abp.TenantId:1005", // "api_key:5W0B8TED1S29TW9SGQ1KXXCJIM9VE2V2",
                            );
                            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                            curl_setopt($curl, CURLOPT_HEADER, 0); // DO NOT RETURN HTTP HEADERS
                            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($curl, CURLOPT_POST, 1);
                            if ($data) {
                                curl_setopt(
                                    $curl,
                                    CURLOPT_POSTFIELDS,
                                    array_merge($data, [
                                        'operation' => 'login',
                                        'accessKey' => md5($challenge['result']['token'] . $data['accessKey'])
                                    ])
                                    // collect(array_merge($data, [
                                    //     'operation' => 'login',
                                    //     'accessKey' => md5($jsonData['result']['token'] . $data['accessKey'])
                                    // ]))->toJson()
                                );
                            }
                            break;
                        case "PUT":
                            curl_setopt($curl, CURLOPT_PUT, 1);
                            break;
                        default:
                            if ($data) {
                                $url = sprintf("%s?%s", $url, http_build_query($data));
                            }
                    }

                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // RETURN THE CONTENTS OF THE CALL

                    $result = curl_exec($curl);
                    // Log::channel($logger)->info($result);

                    if (!curl_errno($curl)) {
                        $info = curl_getinfo($curl);

                        if ($info['http_code'] == 200) {
                        } elseif ($info['http_code'] == 415) {
                        }
                    } else {
                        $result = json_encode([
                            "error" => false,
                            "data" => [
                                "code" => "CHALLENGE_FAILED",
                                "message" => curl_error($curl)
                            ]
                        ]);
                    }

                    curl_close($curl);
                }

                return $result; // return json string
            })(
                $method,
                env('VTIGER_REDIRECT'),
                [
                    'username' => $username,
                    'accessKey' => $accessKey
                ],
                $logger
            ); // return json string

            $result = json_decode($result, true);
        } catch (\Throwable $th) {
            $result = [
                "error" => false,
                "data" => [
                    "code" => "AUTH_FAILED || CHALLENGE_FAILED",
                    "message" => __FUNCTION__ . ": " . $th->getMessage()
                ]
            ];
        }

        return $result;
    }
}

if (!function_exists('apps_toyota_crm_login')) {
    /**
     * Authenticate with Toyota CRM API.
     *
     * Makes a login request to Toyota's authentication endpoint with
     * tenant ID header (Abp.TenantId: 1005).
     *
     * @param string $username Toyota CRM username or email
     * @param string $password User password
     * @param string $method HTTP method (default: 'POST')
     * @param string $logger Log channel name
     * @return array Authentication result with 'error' and 'data' keys
     */
    function apps_toyota_crm_login($username, $password, $method = 'POST', $logger = 'daily')
    {
        try {
            // Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");
            $result = (function ($method, $url, $data, $logger = 'daily') {
                $curl = curl_init();
                switch ($method) {
                    case "POST":
                        $headers = array(
                            "Content-Type:application/json; charset=utf-8",
                            "Abp.TenantId:1005"
                        );
                        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                        curl_setopt($curl, CURLOPT_HEADER, 0); // DO NOT RETURN HTTP HEADERS
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($curl, CURLOPT_POST, 1);
                        if ($data) {
                            curl_setopt($curl, CURLOPT_POSTFIELDS, collect($data)->toJson());
                        }

                        break;
                    case "PUT":
                        curl_setopt($curl, CURLOPT_PUT, 1);
                        break;
                    default:
                        if ($data) {
                            $url = sprintf("%s?%s", $url, http_build_query($data));
                        }
                }

                // curl_setopt($curl, CURLOPT_SSL_CIPHER_LIST, 'ecdhe_rsa_aes_128_gcm_sha_256');
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // RETURN THE CONTENTS OF THE CALL
                $result = curl_exec($curl);

                if (!curl_errno($curl)) {
                    $info = curl_getinfo($curl);

                    if ($info['http_code'] == 200) {
                    } elseif ($info['http_code'] == 415) {
                    }
                } else {
                    $result = json_encode([
                        "error" => false,
                        "data" => [
                            "code" => "AUTH_FAILED",
                            "message" => curl_error($curl)
                        ]
                    ]);
                }

                curl_close($curl);
                return $result; // return json string

            })(
                $method,
                'https://ssa-api.toyotavn.com.vn/api/TokenAuth/Authenticate',
                [
                    'userNameOrEmailAddress' => $username,
                    'password' => $password
                ],
                $logger
            ); // return json string

            $result = json_decode($result, true);
        } catch (\Throwable $th) {
            $result = [
                "error" => false,
                "data" => [
                    "code" => "AUTH_FAILED",
                    "message" => __FUNCTION__ . ": " . $th->getMessage()
                ]
            ];
        }

        return $result;
    }
}

if (!function_exists('apps_mmv_crm_login')) {
    /**
     * Authenticate with Mitsubishi Motors Vietnam (MMV) CRM API.
     *
     * Makes a login request to MMV CRM endpoint using form-encoded data.
     *
     * @param string $username MMV CRM username (default: 'mmv_tsp')
     * @param string $password User password (default: 'Ci8p2P3B')
     * @param string $method HTTP method (default: 'POST')
     * @param string $logger Log channel name
     * @return array Authentication result with 'error' and 'data' keys
     */
    function apps_mmv_crm_login($username = 'mmv_tsp', $password = 'Ci8p2P3B', $method = 'POST', $logger = 'daily'): array
    {
        try {
            // Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");
            $result = (function ($method, $url, $data) {
                $curl = curl_init();

                switch ($method) {
                    case "POST":
                        $headers = array(
                            "Content-Type:application/x-www-form-urlencoded"
                        );
                        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                        curl_setopt($curl, CURLOPT_HEADER, 0); // DO NOT RETURN HTTP HEADERS
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($curl, CURLOPT_POST, 1);
                        if ($data) {
                            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
                        }

                        break;
                    case "PUT":
                        curl_setopt($curl, CURLOPT_PUT, 1);
                        break;
                    default:
                        if ($data) {
                            $url = sprintf("%s?%s", $url, http_build_query($data));
                        }
                }

                // Optional Authentication:
                // curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                // curl_setopt($curl, CURLOPT_USERPWD, "username:password");

                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // RETURN THE CONTENTS OF THE CALL

                $result = curl_exec($curl);

                if (!curl_errno($curl)) {
                    $info = curl_getinfo($curl);
                    if ($info['http_code'] == 200) {
                    } elseif ($info['http_code'] == 415) {
                    }
                } else {
                    $result = json_encode([
                        "error" => false,
                        "data" => [
                            "code" => "AUTH_FAILED",
                            "message" => curl_error($curl)
                        ]
                    ]);
                }

                curl_close($curl);
                return $result; // json string
            })('POST', 'https://crm.mitsubishi-motors.com.vn/api/login', [
                'username' => $username,
                'password' => $password
            ]); // return json string

            $result = json_decode($result, true);
        } catch (\Throwable $th) {
            $result = [
                "error" => false,
                "data" => [
                    "code" => "AUTH_FAILED",
                    "message" => __FUNCTION__ . ": " . $th->getMessage()
                ]
            ];
        }

        return $result;
    }
}

if (!function_exists('apps_scan_folder')) {
    /**
     * Scan a directory and return file/folder names, excluding ignored patterns.
     *
     * Scans the specified directory and returns an array of file/folder names,
     * excluding entries that match ignore patterns. Automatically ignores
     * hidden files (starting with '.') and .DS_Store files.
     *
     * @param string $dir Directory path to scan
     * @param array $ignore_files Array of regex patterns to ignore (merged with default patterns)
     * @return array Sorted array of file/folder names, or empty array on error
     */
    function apps_scan_folder($dir, $ignore_files = [])
    {
        try {
            if (is_dir($dir)) {
                $ignore_pattern = implode('|', array_merge($ignore_files, [
                    '^\.',
                    '.DS_Store'
                ]));
                $datas = preg_grep("/$ignore_pattern/i", scandir($dir), PREG_GREP_INVERT);
                natsort($datas);
                return $datas;
            }
            return [];
        } catch (Exception $ex) {
            return [];
        }
    }
}

if (!function_exists('apps_check_valid_uuid')) {
    /**
     * Check if a given string is a valid UUID v4.
     *
     * Validates UUID format: 8-4-4-4-12 hexadecimal digits with version 4 indicator.
     *
     * @param string $uuid The string to check
     * @return bool True if valid UUID v4, false otherwise
     */
    function apps_check_valid_uuid($uuid)
    {
        if (!is_string($uuid) || (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('apps_apollo_crm_login')) {
    /**
     * Authenticate with Apollo CRM API using Sogo access token.
     *
     * Makes a login request to Apollo CRM endpoint with Sogo access token
     * in the X-Sogo-Access-Token header.
     *
     * @param string $username Apollo CRM username
     * @param string $password User password
     * @param string $sogoAccessToken Sogo access token for authentication
     * @param string $method HTTP method (default: 'POST')
     * @param string $logger Log channel name
     * @return array Authentication result with 'error' and 'data' keys
     */
    function apps_apollo_crm_login($username, $password, $sogoAccessToken, $method = 'POST', $logger = 'daily')
    {
        try {
            // Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");
            $result = (function ($method, $url, $data, $sogoAccessToken, $logger = 'daily') {
                $curl = curl_init();
                switch ($method) {
                    case "POST":
                        $headers = array(
                            "Content-Type:application/json; charset=utf-8",
                            "X-Sogo-Access-Token:$sogoAccessToken"
                        );
                        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                        curl_setopt($curl, CURLOPT_HEADER, 0); // DO NOT RETURN HTTP HEADERS
                        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($curl, CURLOPT_POST, 1);
                        if ($data) {
                            curl_setopt($curl, CURLOPT_POSTFIELDS, collect($data)->toJson());
                        }

                        break;
                    case "PUT":
                        curl_setopt($curl, CURLOPT_PUT, 1);
                        break;
                    default:
                        if ($data) {
                            $url = sprintf("%s?%s", $url, http_build_query($data));
                        }
                }

                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // RETURN THE CONTENTS OF THE CALL

                $result = curl_exec($curl);

                if (!curl_errno($curl)) {
                    $info = curl_getinfo($curl);
                    if ($info['http_code'] == 200) {
                    } elseif ($info['http_code'] == 415) {
                    }
                } else {
                    $result = json_encode([
                        "error" => false,
                        "data" => [
                            "code" => "AUTH_FAILED",
                            "message" => curl_error($curl)
                        ]
                    ]);
                }

                curl_close($curl);
                return $result; // json string
            })(
                $method,
                'http://api-qc.apollo.vn/auths/verify-login/',
                [
                    'username' => $username,
                    'password' => $password
                ],
                $sogoAccessToken,
                $logger
            ); // return json string

            $result = json_decode($result, true);
        } catch (\Throwable $th) {
            $result = [
                "error" => false,
                "data" => [
                    "code" => "AUTH_FAILED",
                    "message" => __FUNCTION__ . ": " . $th->getMessage()
                ]
            ];
        }

        return $result;
    }
}
if (!function_exists('apps_json_to_database')) {
    /**
     * Convert JSON data to array and add/update a value before storing to database.
     *
     * Converts JSON string to array if needed, then adds or updates a value
     * at the specified key path. Supports nested keys using dot notation.
     *
     * Note: Prefer using model casts for JSON columns instead of manual json_decode/json_encode.
     *
     * @param array|string|null $original Original data (JSON string or array)
     * @param mixed $value Value to add/update
     * @param string|null $key Key path (supports dot notation, e.g., 'notifications.webhook')
     * @param bool $override If true, overwrite existing value; if false, merge with existing
     * @return array Updated array
     */
    function apps_json_to_database(array|string|null $original, $value, $key = null, $override = true): array
    {
        if (is_string($original)) { // '{}'
            try {
                $original = json_decode(blank($original) ? '{}' : $original, true, 512, JSON_THROW_ON_ERROR); // or if sure json is stable: $original = json_decode(blank($original) ? '{}' : $original, true);
            } catch (\JsonException $e) {
                Log::error('[apps_json_to_database] JSON decode failed', ['input' => $original, 'error' => $e->getMessage()]);
                $original = []; // safe fallback
            }
        }
        $original = (array) $original;

        if (!blank($key)) {
            Arr::set(
                $original,
                $key,

                $override ? $value : array_merge(
                    Arr::get(
                        $original,
                        $key, // eg: notifications.leadgen_notification_external_webhook or webhook
                        []
                    ),
                    $value
                )

            );
        } else {
            $original = array_merge($original, $value);
        }

        return $original;
    }
}

if (!function_exists('apps_province_detection')) {
    /**
     * Detect Vietnamese province name from a string.
     *
     * Normalizes the input string and matches it against a list of Vietnamese provinces.
     * Returns the full province name if a match is found.
     *
     * @example
     * apps_province_detection('(Thanh Hoá) ĐL Thanh Hoá_TTH') // Returns "Thanh Hóa"
     *
     * @param string $term Input string containing province name
     * @return string Full province name or empty string if not found
     */
    function apps_province_detection($term): string
    {
        $term = Str::slug(trim($term), '');
        $cities = ["brvt" => "Bà Rịa-Vũng Tàu", "hcm" => "Tp. Hồ Chí Minh", "tphcm" => "Tp. Hồ Chí Minh", "angiang" => "An Giang", "bariavungtau" => "Bà Rịa-Vũng Tàu", "baclieu" => "Bạc Liêu", "backan" => "Bắc Kạn", "bacgiang" => "Bắc Giang", "bacninh" => "Bắc Ninh", "bentre" => "Bến Tre", "binhduong" => "Bình Dương", "binhdinh" => "Bình Định", "binhphuoc" => "Bình Phước", "binhthuan" => "Bình Thuận", "camau" => "Cà Mau", "caobang" => "Cao Bằng", "cantho" => "Cần Thơ", "danang" => "Đà Nẵng", "daklak" => "Đắk Lắk", "daknong" => "Đắk Nông", "dienbien" => "Điện Biên", "dongnai" => "Đồng Nai", "dongthap" => "Đồng Tháp", "gialai" => "Gia Lai", "hagiang" => "Hà Giang", "hanam" => "Hà Nam", "hanoi" => "Hà Nội", "hatay" => "Hà Tây", "hatinh" => "Hà Tĩnh", "haiduong" => "Hải Dương", "haiphong" => "Hải Phòng", "hoabinh" => "Hòa Bình", "hochiminh" => "Hồ Chí Minh", "haugiang" => "Hậu Giang", "hungyen" => "Hưng Yên", "khanhhoa" => "Khánh Hòa", "kiengiang" => "Kiên Giang", "kontum" => "Kon Tum", "laichau" => "Lai Châu", "laocai" => "Lào Cai", "langson" => "Lạng Sơn", "lamdong" => "Lâm Đồng", "longan" => "Long An", "namdinh" => "Nam Định", "nghean" => "Nghệ An", "ninhbinh" => "Ninh Bình", "ninhthuan" => "Ninh Thuận", "phutho" => "Phú Thọ", "phuyen" => "Phú Yên", "quangbinh" => "Quảng Bình", "quangnam" => "Quảng Nam", "quangngai" => "Quảng Ngãi", "quangninh" => "Quảng Ninh", "quangtri" => "Quảng Trị", "soctrang" => "Sóc Trăng", "sonla" => "Sơn La", "tayninh" => "Tây Ninh", "thaibinh" => "Thái Bình", "thainguyen" => "Thái Nguyên", "thanhhoa" => "Thanh Hóa", "thuathienhue" => "Thừa Thiên - Huế", "tiengiang" => "Tiền Giang", "travinh" => "Trà Vinh", "tuyenquang" => "Tuyên Quang", "vinhlong" => "Vĩnh Long", "vinhphuc" => "Vĩnh Phúc", "yenbai" => "Yên Bái"];
        $filtered = collect($cities)->filter(function ($value, $key) use ($term) {
            return Str::contains($term, $key);
        });

        return $filtered->first(); // return An Giang for example
    }
}

if (!function_exists('apps_unicode_decode')) {
    /**
     * Decode Unicode escape sequences in a string.
     * Optimized: fast pre-check with strpos and string-only focus.
     *
     * @param mixed $str
     * @return mixed
     */
    function apps_unicode_decode($str)
    {
        if (!is_string($str) || strpos($str, '\\') === false) {
            return $str;
        }

        return preg_replace_callback('/\\\\[Uu]([0-9A-Fa-f]{4})/', function ($m) {
            return json_decode('"\u' . $m[1] . '"');
        }, $str);
    }
}


if (!function_exists('apps_get_provinces_districts_rawdata')) {
    /**
     * Get Vietnamese provinces with their districts, or districts for a specific province.
     *
     * Returns a comprehensive list of all Vietnamese provinces with their districts,
     * or filters to return only districts for a specific province if $term is provided.
     *
     * @example
     * apps_get_provinces_districts_rawdata('Thanh Hoá') // Returns districts of Thanh Hóa
     * apps_get_provinces_districts_rawdata() // Returns all provinces with districts
     *
     * @param string|null $term Province name to filter (optional)
     * @return array Associative array of provinces => districts, or districts array if $term provided
     */
    function apps_get_provinces_districts_rawdata($term = null): array
    {
        $term = Str::slug(trim($term), '');

        $districts = [
            "Hà Nội" => ["Quận Ba Đình", "Quận Hoàn Kiếm", "Quận Tây Hồ", "Quận Long Biên", "Quận Cầu Giấy", "Quận Đống Đa", "Quận Hai Bà Trưng", "Quận Hoàng Mai", "Quận Thanh Xuân", "Huyện Sóc Sơn", "Huyện Đông Anh", "Huyện Gia Lâm", "Quận Nam Từ Liêm", "Huyện Thanh Trì", "Quận Bắc Từ Liêm", "Huyện Mê Linh", "Quận Hà Đông", "Thị xã Sơn Tây", "Huyện Ba Vì", "Huyện Phúc Thọ", "Huyện Đan Phượng", "Huyện Hoài Đức", "Huyện Quốc Oai", "Huyện Thạch Thất", "Huyện Chương Mỹ", "Huyện Thanh Oai", "Huyện Thường Tín", "Huyện Phú Xuyên", "Huyện Ứng Hòa", "Huyện Mỹ Đức"],
            "Thành phố Hồ Chí Minh" => ["Quận 1", "Quận 12", "Quận Gò Vấp", "Quận Bình Thạnh", "Quận Tân Bình", "Quận Tân Phú", "Quận Phú Nhuận", "Thành phố Thủ Đức", "Quận 3", "Quận 10", "Quận 11", "Quận 4", "Quận 5", "Quận 6", "Quận 8", "Quận Bình Tân", "Quận 7", "Huyện Củ Chi", "Huyện Hóc Môn", "Huyện Bình Chánh", "Huyện Nhà Bè", "Huyện Cần Giờ"],
            "Hải Phòng" => ["Quận Hồng Bàng", "Quận Ngô Quyền", "Quận Lê Chân", "Quận Hải An", "Quận Kiến An", "Quận Đồ Sơn", "Quận Dương Kinh", "Huyện Thuỷ Nguyên", "Huyện An Dương", "Huyện An Lão", "Huyện Kiến Thuỵ", "Huyện Tiên Lãng", "Huyện Vĩnh Bảo", "Huyện Cát Hải", "Huyện Bạch Long Vĩ"],
            "Đà Nẵng" => ["Quận Liên Chiểu", "Quận Thanh Khê", "Quận Hải Châu", "Quận Sơn Trà", "Quận Ngũ Hành Sơn", "Quận Cẩm Lệ", "Huyện Hòa Vang", "Huyện Hoàng Sa"],
            "Cần Thơ" => ["Quận Ninh Kiều", "Quận Ô Môn", "Quận Bình Thuỷ", "Quận Cái Răng", "Quận Thốt Nốt", "Huyện Cờ Đỏ", "Huyện Thới Lai"],
            "An Giang" => ["Thành phố Long Xuyên", "Thành phố Châu Đốc", "Huyện An Phú", "Thị xã Tân Châu", "Huyện Phú Tân", "Huyện Châu Phú", "Huyện Tịnh Biên", "Huyện Tri", "Tôn Huyện Thoại Sơn"],
            "Bà Rịa - Vũng Tàu" => ["Thành phố Vũng Tàu", "Thành phố Bà Rịa", "Huyện Châu Đức", "Huyện Xuyên Mộc", "Huyện Long Điền", "Huyện Đất Đỏ", "Thị xã Phú Mỹ", "Huyện Côn Đảo"],
            "Bạc Liêu" => ["Thành phố Bạc Liêu", "Huyện Hồng Dân", "Huyện Phước Long", "Huyện Vĩnh Lợi", "Thị xã Giá Rai", "Huyện Đông Hải", "Huyện Hoà Bình"],
            "Bắc Giang" => ["Thành phố Bắc Giang", "Huyện Yên Thế", "Huyện Tân Yên", "Huyện Lạng Giang", "Huyện Lục Nam", "Huyện Lục Ngạn", "Huyện Sơn Động", "Huyện Yên Dũng", "Huyện Việt Yên", "Huyện Hiệp Hòa"],
            "Bắc Kạn" => ["Thành Phố Bắc Kạn", "Huyện Pác Nặm", "Huyện Ba Bể", "Huyện Ngân Sơn", "Huyện Bạch Thông", "Huyện Chợ Đồn", "Huyện Chợ Mới", "Huyện Na Rì"],
            "Bắc Ninh" => ["Thành phố Bắc Ninh", "Huyện Yên Phong", "Huyện Quế Võ", "Huyện Tiên Du", "Thành phố Từ Sơn", "Huyện Thuận Thành", "Huyện Gia Bình", "Huyện Lương Tài"],
            "Bến Tre" => ["Thành phố Bến Tre", "Huyện Chợ Lách", "Huyện Mỏ Cày Nam", "Huyện Giồng Trôm", "Huyện Bình Đại", "Huyện Ba Tri", "Huyện Thạnh Phú", "Huyện Mỏ Cày Bắc"],
            "Bình Dương" => ["Thành phố Thủ Dầu Một", "Huyện Bàu Bàng", "Huyện Dầu Tiếng", "Thị xã Bến Cát", "Huyện Phú Giáo", "Thị xã Tân Uyên", "Thành phố Dĩ An", "Thành phố Thuận An", "Huyện Bắc Tân Uyên"],
            "Bình Định" => ["Thành phố Quy Nhơn", "Thị xã Hoài Nhơn", "Huyện Hoài Ân", "Huyện Phù Mỹ", "Huyện Vĩnh Thạnh", "Huyện Tây Sơn", "Huyện Phù Cát", "Thị xã An Nhơn", "Huyện Tuy Phước", "Huyện Vân Canh"],
            "Bình Phước" => ["Thị xã Phước Long", "Thành phố Đồng Xoài", "Thị xã Bình Long", "Huyện Bù Gia Mập", "Huyện Lộc Ninh", "Huyện Bù Đốp", "Huyện Hớn Quản", "Huyện Đồng Phú", "Huyện Bù Đăng", "Thị xã Chơn Thành", "Huyện Phú Riềng"],
            "Bình Thuận" => ["Thành phố Phan Thiết", "Thị xã La Gi", "Huyện Tuy Phong", "Huyện Bắc Bình", "Huyện Hàm Thuận Bắc", "Huyện Hàm Thuận Nam", "Huyện Tánh Linh", "Huyện Đức Linh", "Huyện Hàm Tân", "Huyện Phú Quí"],
            "Cà Mau" => ["Thành phố Cà Mau", "Huyện U Minh", "Huyện Thới Bình", "Huyện Trần Văn Thời", "Huyện Cái Nước", "Huyện Đầm Dơi", "Huyện Năm Căn", "Huyện Ngọc Hiển"],
            "Cao Bằng" => ["Thành phố Cao Bằng", "Huyện Bảo Lâm", "Huyện Bảo Lạc", "Huyện Hà Quảng", "Huyện Trùng Khánh", "Huyện Hạ Lang", "Huyện Quảng Hòa", "Huyện Hoà An", "Huyện Nguyên Bình", "Huyện Thạch An"],
            "Đắk Lắk" => ["Thành phố Buôn Ma Thuột", "Thị Xã Buôn Hồ", "Huyện Ea H'leo", "Huyện Ea Súp", "Huyện Buôn Đôn", "Huyện Cư M'gar", "Huyện Krông Búk", "Huyện Krông Năng", "Huyện Ea Kar", "Huyện M'Đrắk", "Huyện Krông Bông", "Huyện Krông Pắc", "Huyện Krông A Na", "Huyện Lắk", "Huyện Cư Kuin"],
            "Đắk Nông" => ["Thành phố Gia Nghĩa", "Huyện Đăk Glong", "Huyện Cư Jút", "Huyện Đắk Mil", "Huyện Krông Nô", "Huyện Đắk Song", "Huyện Đắk R'Lấp", "Huyện Tuy Đức"],
            "Đồng Nai" => ["Thành phố Biên Hòa", "Thành phố Long Khánh", "Huyện Tân Phú", "Huyện Vĩnh Cửu", "Huyện Định Quán", "Huyện Trảng Bom", "Huyện Thống Nhất", "Huyện Cẩm Mỹ", "Huyện Long Thành", "Huyện Xuân Lộc", "Huyện Nhơn Trạch"],
            "Đồng Tháp" => ["Thành phố Cao Lãnh", "Thành phố Sa Đéc", "Thành phố Hồng Ngự", "Huyện Tân Hồng", "Huyện Hồng Ngự", "Huyện Tháp Mười", "Huyện Cao Lãnh", "Huyện Thanh Bình", "Huyện Lấp Vò", "Huyện Lai Vung"],
            "Gia Lai" => ["Thành phố Pleiku", "Thị xã An Khê", "Thị xã Ayun Pa", "Huyện KBang", "Huyện Đăk Đoa", "Huyện Chư Păh", "Huyện Ia Grai", "Huyện Mang Yang", "Huyện Kông Chro", "Huyện Đức Cơ", "Huyện Chư Prông", "Huyện Chư Sê", "Huyện Đăk Pơ", "Huyện Ia Pa", "Huyện Krông Pa", "Huyện Phú Thiện", "Huyện Chư Pưh"],
            "Hà Giang" => ["Thành phố Hà Giang", "Huyện Đồng Văn", "Huyện Mèo Vạc", "Huyện Yên Minh", "Huyện Quản Bạ", "Huyện Vị Xuyên", "Huyện Bắc Mê", "Huyện Hoàng Su Phì", "Huyện Xín Mần", "Huyện Bắc Quang", "Huyện Quang Bình"],
            "Hà Nam" => ["Thành phố Phủ Lý", "Thị xã Duy Tiên", "Huyện Kim Bảng", "Huyện Thanh Liêm", "Huyện Bình Lục", "Huyện Lý Nhân"],
            "Hà Tĩnh" => ["Thành phố Hà Tĩnh", "Thị xã Hồng Lĩnh", "Huyện Hương Sơn", "Huyện Đức Thọ", "Huyện Vũ Quang", "Huyện Nghi Xuân", "Huyện Can Lộc", "Huyện Hương Khê", "Huyện Thạch Hà", "Huyện Cẩm Xuyên", "Huyện Kỳ Anh", "Huyện Lộc Hà", "Thị xã Kỳ Anh"],
            "Hải Dương" => ["Thành phố Hải Dương", "Thành phố Chí Linh", "Huyện Nam Sách", "Thị xã Kinh Môn", "Huyện Kim Thành", "Huyện Thanh Hà", "Huyện Cẩm Giàng", "Huyện Bình Giang", "Huyện Gia Lộc", "Huyện Tứ Kỳ", "Huyện Ninh Giang", "Huyện Thanh Miện"],
            "Hậu Giang" => ["Thành phố Vị Thanh", "Thành phố Ngã Bảy", "Huyện Châu Thành A", "Huyện Phụng Hiệp", "Huyện Vị Thuỷ", "Huyện Long Mỹ", "Thị xã Long Mỹ"],
            "Hòa Bình" => ["Thành phố Hòa Bình", "Huyện Đà Bắc", "Huyện Lương Sơn", "Huyện Kim Bôi", "Huyện Cao Phong", "Huyện Tân Lạc", "Huyện Mai Châu", "Huyện Lạc Sơn", "Huyện Yên Thủy", "Huyện Lạc Thủy"],
            "Hưng Yên" => ["Thành phố Hưng Yên", "Huyện Văn Lâm", "Huyện Văn Giang", "Huyện Yên Mỹ", "Thị xã Mỹ Hào", "Huyện Ân Thi", "Huyện Khoái Châu", "Huyện Kim Động", "Huyện Tiên Lữ", "Huyện Phù Cừ"],
            "Khánh Hòa" => ["Thành phố Nha Trang", "Thành phố Cam Ranh", "Huyện Cam Lâm", "Huyện Vạn Ninh", "Thị xã Ninh Hòa", "Huyện Khánh Vĩnh", "Huyện Diên Khánh", "Huyện Khánh Sơn", "Huyện Trường Sa"],
            "Tuyên Quang" => ["Thành phố Tuyên Quang", "Huyện Lâm Bình", "Huyện Na Hang", "Huyện Chiêm Hóa", "Huyện Hàm Yên", "Huyện Yên Sơn", "Huyện Sơn Dương"],
            "Lào Cai" => ["Thành phố Lào Cai", "Huyện Bát Xát", "Huyện Mường Khương", "Huyện Si Ma Cai", "Huyện Bắc Hà", "Huyện Bảo Thắng", "Huyện Bảo Yên", "Thị xã Sa Pa", "Huyện Văn Bàn"],
            "Điện Biên" => ["Thành phố Điện Biên Phủ", "Thị Xã Mường Lay", "Huyện Mường Nhé", "Huyện Mường Chà", "Huyện Tủa Chùa", "Huyện Tuần Giáo", "Huyện Điện Biên", "Huyện Điện Biên Đông", "Huyện Mường Ảng", "Huyện Nậm Pồ"],
            "Lai Châu" => ["Thành phố Lai Châu", "Huyện Tam Đường", "Huyện Mường Tè", "Huyện Sìn Hồ", "Huyện Phong Thổ", "Huyện Than Uyên", "Huyện Tân Uyên", "Huyện Nậm Nhùn"],
            "Sơn La" => ["Thành phố Sơn La", "Huyện Quỳnh Nhai", "Huyện Thuận Châu", "Huyện Mường La", "Huyện Bắc Yên", "Huyện Phù Yên", "Huyện Mộc Châu", "Huyện Yên Châu", "Huyện Mai Sơn", "Huyện Sông Mã", "Huyện Sốp Cộp", "Huyện Vân Hồ"],
            "Yên Bái" => ["Thành phố Yên Bái", "Thị xã Nghĩa Lộ", "Huyện Lục Yên", "Huyện Văn Yên", "Huyện Mù Căng Chải", "Huyện Trấn Yên", "Huyện Trạm Tấu", "Huyện Văn Chấn", "Huyện Yên Bình"],
            "Thái Nguyên" => ["Thành phố Thái Nguyên", "Thành phố Sông Công", "Huyện Định Hóa", "Huyện Phú Lương", "Huyện Đồng Hỷ", "Huyện Võ Nhai", "Huyện Đại Từ", "Thành phố Phổ Yên", "Huyện Phú Bình"],
            "Lạng Sơn" => ["Thành phố Lạng Sơn", "Huyện Tràng Định", "Huyện Bình Gia", "Huyện Văn Lãng", "Huyện Cao Lộc", "Huyện Văn Quan", "Huyện Bắc Sơn", "Huyện Hữu Lũng", "Huyện Chi Lăng", "Huyện Lộc Bình", "Huyện Đình Lập"],
            "Quảng Ninh" => ["Thành phố Hạ Long", "Thành phố Móng Cái", "Thành phố Cẩm Phả", "Thành phố Uông Bí", "Huyện Bình Liêu", "Huyện Tiên Yên", "Huyện Đầm Hà", "Huyện Hải Hà", "Huyện Ba Chẽ", "Huyện Vân Đồn", "Thị xã Đông Triều", "Thị xã Quảng Yên", "Huyện Cô Tô"],
            "Phú Thọ" => ["Thành phố Việt Trì", "Thị xã Phú Thọ", "Huyện Đoan Hùng", "Huyện Hạ Hoà", "Huyện Thanh Ba", "Huyện Phù Ninh", "Huyện Yên Lập", "Huyện Cẩm Khê", "Huyện Tam Nông", "Huyện Lâm Thao", "Huyện Thanh Sơn", "Huyện Thanh Thuỷ", "Huyện Tân Sơn"],
            "Vĩnh Phúc" => ["Thành phố Vĩnh Yên", "Thành phố Phúc Yên", "Huyện Lập Thạch", "Huyện Tam Dương", "Huyện Tam Đảo", "Huyện Bình Xuyên", "Huyện Yên Lạc", "Huyện Vĩnh Tường", "Huyện Sông Lô"],
            "Thái Bình" => ["Thành phố Thái Bình", "Huyện Quỳnh Phụ", "Huyện Hưng Hà", "Huyện Đông Hưng", "Huyện Thái Thụy", "Huyện Tiền Hải", "Huyện Kiến Xương", "Huyện Vũ Thư"],
            "Nam Định" => ["Thành phố Nam Định", "Huyện Mỹ Lộc", "Huyện Vụ Bản", "Huyện Ý Yên", "Huyện Nghĩa Hưng", "Huyện Nam Trực", "Huyện Trực Ninh", "Huyện Xuân Trường", "Huyện Giao Thủy", "Huyện Hải Hậu"],
            "Ninh Bình" => ["Thành phố Ninh Bình", "Thành phố Tam Điệp", "Huyện Nho Quan", "Huyện Gia Viễn", "Huyện Hoa Lư", "Huyện Yên Khánh", "Huyện Kim Sơn", "Huyện Yên Mô"],
            "Thanh Hóa" => ["Thành phố Thanh Hóa", "Thị xã Bỉm Sơn", "Thành phố Sầm Sơn", "Huyện Mường Lát", "Huyện Quan Hóa", "Huyện Bá Thước", "Huyện Quan Sơn", "Huyện Lang Chánh", "Huyện Ngọc Lặc", "Huyện Cẩm Thủy", "Huyện Thạch Thành", "Huyện Hà Trung", "Huyện Vĩnh Lộc", "Huyện Yên Định", "Huyện Thọ Xuân", "Huyện Thường Xuân", "Huyện Triệu Sơn", "Huyện Thiệu Hóa", "Huyện Hoằng Hóa", "Huyện Hậu Lộc", "Huyện Nga Sơn", "Huyện Như Xuân", "Huyện Như Thanh", "Huyện Nông Cống", "Huyện Đông Sơn", "Huyện Quảng Xương", "Thị xã Nghi Sơn"],
            "Nghệ An" => ["Thành phố Vinh", "Thị xã Cửa Lò", "Thị xã Thái Hoà", "Huyện Quế Phong", "Huyện Quỳ Châu", "Huyện Kỳ Sơn", "Huyện Tương Dương", "Huyện Nghĩa Đàn", "Huyện Quỳ Hợp", "Huyện Quỳnh Lưu", "Huyện Con Cuông", "Huyện Tân Kỳ", "Huyện Anh Sơn", "Huyện Diễn Châu", "Huyện Yên Thành", "Huyện Đô Lương", "Huyện Thanh Chương", "Huyện Nghi Lộc", "Huyện Nam Đàn", "Huyện Hưng Nguyên", "Thị xã Hoàng Mai"],
            "Quảng Bình" => ["Thành Phố Đồng Hới", "Huyện Minh Hóa", "Huyện Tuyên Hóa", "Huyện Quảng Trạch", "Huyện Bố Trạch", "Huyện Quảng Ninh", "Huyện Lệ Thủy", "Thị xã Ba Đồn"],
            "Quảng Trị" => ["Thành phố Đông Hà", "Thị xã Quảng Trị", "Huyện Vĩnh Linh", "Huyện Hướng Hóa", "Huyện Gio Linh", "Huyện Đa Krông", "Huyện Cam Lộ", "Huyện Triệu Phong", "Huyện Hải Lăng", "Huyện Cồn Cỏ"],
            "Thừa Thiên Huế" => ["Thành phố Huế", "Huyện Phong Điền", "Huyện Quảng Điền", "Huyện Phú Vang", "Thị xã Hương Thủy", "Thị xã Hương Trà", "Huyện A Lưới", "Huyện Phú Lộc", "Huyện Nam Đông"],
            "Quảng Nam" => ["Thành phố Tam Kỳ", "Thành phố Hội An", "Huyện Tây Giang", "Huyện Đông Giang", "Huyện Đại Lộc", "Thị xã Điện Bàn", "Huyện Duy Xuyên", "Huyện Quế Sơn", "Huyện Nam Giang", "Huyện Phước Sơn", "Huyện Hiệp Đức", "Huyện Thăng Bình", "Huyện Tiên Phước", "Huyện Bắc Trà My", "Huyện Nam Trà My", "Huyện Núi Thành", "Huyện Phú Ninh", "Huyện Nông Sơn"],
            "Quảng Ngãi" => ["Thành phố Quảng Ngãi", "Huyện Bình Sơn", "Huyện Trà Bồng", "Huyện Sơn Tịnh", "Huyện Tư Nghĩa", "Huyện Sơn Hà", "Huyện Sơn Tây", "Huyện Minh Long", "Huyện Nghĩa Hành", "Huyện Mộ Đức", "Thị xã Đức Phổ", "Huyện Ba Tơ", "Huyện Lý Sơn"],
            "Phú Yên" => ["Thành phố Tuy Hoà", "Thị xã Sông Cầu", "Huyện Đồng Xuân", "Huyện Tuy An", "Huyện Sơn Hòa", "Huyện Sông Hinh", "Huyện Tây Hoà", "Huyện Phú Hoà", "Thị xã Đông Hòa"],
            "Ninh Thuận" => ["Thành phố Phan Rang-Tháp Chàm", "Huyện Bác Ái", "Huyện Ninh Sơn", "Huyện Ninh Hải", "Huyện Ninh Phước", "Huyện Thuận Bắc", "Huyện Thuận Nam"],
            "Kon Tum" => ["Thành phố Kon Tum", "Huyện Đắk Glei", "Huyện Ngọc Hồi", "Huyện Đắk Tô", "Huyện Kon Plông", "Huyện Kon Rẫy", "Huyện Đắk Hà", "Huyện Sa Thầy", "Huyện Tu Mơ Rông", "Huyện Ia H' Drai"],
            "Bạc Liêu" => ["Thành phố Bạc Liêu", "Huyện Hồng Dân", "Huyện Phước Long", "Huyện Vĩnh Lợi", "Thị xã Giá Rai", "Huyện Đông Hải", "Huyện Hoà Bình"],
            "Lâm Đồng" => ["Thành phố Đà Lạt", "Thành phố Bảo Lộc", "Huyện Đam Rông", "Huyện Lạc Dương", "Huyện Lâm Hà", "Huyện Đơn Dương", "Huyện Đức Trọng", "Huyện Di Linh", "Huyện Đạ Huoai", "Huyện Đạ Tẻh", "Huyện Cát Tiên"],
            "Tây Ninh" => ["Thành phố Tây Ninh", "Huyện Tân Biên", "Huyện Tân Châu", "Huyện Dương Minh Châu", "Huyện Châu Thành", "Thị xã Hòa Thành", "Huyện Gò Dầu", "Huyện Bến Cầu", "Thị xã Trảng Bàng"],
            "Long An" => ["Thành phố Tân An", "Thị xã Kiến Tường", "Huyện Tân Hưng", "Huyện Vĩnh Hưng", "Huyện Mộc Hóa", "Huyện Tân Thạnh", "Huyện Thạnh Hóa", "Huyện Đức Huệ", "Huyện Đức Hòa", "Huyện Bến Lức", "Huyện Thủ Thừa", "Huyện Tân Trụ", "Huyện Cần Đước", "Huyện Cần Giuộc"],
            "Tiền Giang" => ["Thành phố Mỹ Tho", "Thị xã Gò Công", "Thị xã Cai Lậy", "Huyện Tân Phước", "Huyện Cái Bè", "Huyện Cai Lậy", "Huyện Chợ Gạo", "Huyện Gò Công Tây", "Huyện Gò Công Đông", "Huyện Tân Phú Đông"],
            "Trà Vinh" => ["Thành phố Trà Vinh", "Huyện Càng Long", "Huyện Cầu Kè", "Huyện Tiểu Cần", "Huyện Cầu Ngang", "Huyện Trà Cú", "Huyện Duyên Hải", "Thị xã Duyên Hải"],
            "Vĩnh Long" => ["Thành phố Vĩnh Long", "Huyện Long Hồ", "Huyện Mang Thít", "Huyện Vũng Liêm", "Huyện Tam Bình", "Thị xã Bình Minh", "Huyện Trà Ôn", "Huyện Bình Tân"],
            "Kiên Giang" => ["Thành phố Rạch Giá", "Thành phố Hà Tiên", "Huyện Kiên Lương", "Huyện Hòn Đất", "Huyện Tân Hiệp", "Huyện Giồng Riềng", "Huyện Gò Quao", "Huyện An Biên", "Huyện An Minh", "Huyện Vĩnh Thuận", "Thành phố Phú Quốc", "Huyện Kiên Hải", "Huyện U Minh Thượng", "Huyện Giang Thành"]
        ];

        $districts = array_combine(
            array_map(function ($key) {
                return Str::slug($key, ''); // no-space in key
            }, array_keys($districts)),
            array_values($districts)
        );

        if (!blank($term)) {
            return Arr::get($districts, $term, []);
        }
        return $districts;
    }
}

if (!function_exists('apps_facebook_parse_signed_request')) {
    /**
     * Parse and verify a Facebook signed request.
     *
     * Decodes a Facebook signed request and verifies its signature using HMAC-SHA256.
     * Returns the decoded payload if signature is valid, null otherwise.
     *
     * @param string $signed_request Facebook signed request string (format: encoded_sig.payload)
     * @param string $logger Log channel name
     * @return array|null Decoded payload data or null if signature is invalid
     */
    function apps_facebook_parse_signed_request($signed_request, $logger = 'daily')
    {
        list($encoded_sig, $payload) = explode('.', $signed_request, 2);

        $secret = "85305f45d80a5322abf9dc954f52fc8e"; // Use your app secret here

        // decode the data
        $sig = apps_facebook_base64_url_decode($encoded_sig);
        $data = json_decode(apps_facebook_base64_url_decode($payload), true);

        // confirm the signature
        $expected_sig = hash_hmac('sha256', $payload, $secret, $raw = true);
        if ($sig !== $expected_sig) {
            // Log::channel($logger)->info('Bad Signed JSON signature!');
            error_log('Bad Signed JSON signature!');
            return null;
        }

        return $data;
    }
}
if (!function_exists('apps_facebook_base64_url_decode')) {
    /**
     * Decode Facebook's URL-safe base64 encoding.
     *
     * Converts URL-safe base64 characters (- and _) to standard base64 characters (+ and /).
     *
     * @param string $input URL-safe base64 encoded string
     * @return string Decoded string
     */
    function apps_facebook_base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}

if (!function_exists('apps_telegram_send_message')) {
    /**
     * Send a message via Telegram with default configurations.
     *
     * Sends a message to a Telegram channel using the configured bot token.
     * Only works in production environment. Supports HTML formatting and custom options.
     *
     * @param string|array $message Message text or array of lines
     * @param string $channel Telegram bot channel name (default: 'pull')
     * @param string $logger Log channel name
     * @param array $configs Additional Telegram API options to override defaults
     * @return \Telegram\Bot\Objects\Message|null
     */
    function apps_telegram_send_message(
        $message,
        $channel = 'pull',
        $logger = 'daily',
        $configs = []
    ) {
        #region Important Telegram notification
        try {
            if (blank($logger))
                $logger = 'daily';

            if (!in_array(app()->environment(), ['production', 'prod'], true) && !env('TELEGRAM_NOTIFY_ENABLE', false)) { // giới hạn gửi ở production, hoặc nếu bật force notify
                Log::channel($logger)->info('[Telegram::Skipped]', [
                    'env' => app()->environment(),
                    'reason' => 'Not in production and TELEGRAM_NOTIFY_ENABLE is not true'
                ]);
                return;
            }


            $telegramDfOptions = [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'link_preview_options' => ['is_disabled' => true],
                'chat_id' => env('TELEGRAM_CHAT_ID', config("telegram.bots.{$channel}.chat_id", '-1001541977083')),
                'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID', config("telegram.bots.{$channel}.message_thread_id", '-20958'))
            ];

            if (!blank($configs)) {
                $telegramDfOptions = [
                    ...$telegramDfOptions,
                    ...$configs // high priority & override
                ];
            }

            Log::channel($logger)->info("configs", $configs);
            Log::channel($logger)->info("telegramDfOptions", $telegramDfOptions);

            $telegramDfOptions['text'] = \Illuminate\Support\Str::limit( // Can use facades app('url) but helper str() not available right now, possibly because not loaded
                is_array($message) ?
                implode("\n", $message) : $message,
                4096
            );

            $botToken = config("telegram.bots.{$channel}.token", env('TELEGRAM_BOT_TOKEN'));
            $telegram = new \Telegram\Bot\Api($botToken); // getenv TELEGRAM_BOT_TOKEN, it's working
            return $telegram->sendMessage($telegramDfOptions);
        } catch (Throwable $th) {
            Log::channel($logger)->error(__FUNCTION__, (array) $th->getMessage());
            // DO NOT THROW
            return null;
        }
        #endregion
    }
}

if (!function_exists('apps_telegram_clean_markdown')) {
    /**
     * Clean special markdown characters to prevent Telegram parsing errors
     *
     * @param string $text
     * @return string
     */
    function apps_telegram_clean_markdown($text)
    {
        if (!$text) return "";
        return str_replace(['_', '*', '`', '['], '', $text);
    }
}

if (!function_exists('apps_phone_extraction')) {
    /**
     * Extract phone number from a string using regex pattern.
     *
     * Normalizes the input string and extracts the first phone number
     * matching the specified pattern. Default pattern supports international
     * and local formats with various separators.
     *
     * @param string $str Input string containing phone number
     * @param string $pattern Regex pattern for phone number matching
     * @return string|null Extracted phone number or null if not found
     */
    function apps_phone_extraction($str, $pattern = '/(?:\+?\d[\s\.\-\(\)]*){10,15}/')
    {
        preg_match_all($pattern, $str, $matches); // custom pattern

        if (!empty($matches[0])) {
            foreach ($matches[0] as $match) {
                // clean up the matched string
                $clean = preg_replace('/[\s\.\-\(\)]/', '', $match);

                // Check valid VN phone:
                // - Starts with 0, length 10 or 11
                // - Starts with 84, length 11 or 12
                // - Starts with +84, length 12 or 13
                if (preg_match('/^(?:\+84|84|0)[0-9]{9,10}$/', $clean)) {
                    return trim((string) $match);
                }

                // Fallback for general international 10-15 digits
                if (preg_match('/^\+?\d{10,15}$/', $clean)) {
                    return trim((string) $match);
                }
            }
        }

        return null;
    }
}

#region bộ hàm apps_cache_*
/**
 * Create a cache key and remember which group it belongs to.
 * If $group is passed:
 * Register this key in the group_xxx list (array type storing max 100 keys).
 * Register group name in global list (for later reset).
 * If no $group, simply return $cacheKey.
 *
 * @param string $cacheKey: specific cache name (example: user_1234)
 * @param string $group: cache group name (example: user_list)
 *
 * Returns the key to use for storing in cache.
 */
if (!function_exists('apps_cache_get_key')) {
    /**
     * Automatically create key in group list
     * Save group in the list of keys for management and deletion
     * Store max 100 keys per group to avoid redis or machine memory overload.
     * When reaching limit, the first element (FIFO) will be deleted from cache and groupData.
     *
     * @param string $cacheKey : Cache key in group, if not present then add queue to group's key list
     * @param string|null $group : Cache group name, contains group's common keys. if set to null then it's a separate cacheKey not in any group
     * @return string Applied key with prefix (app:cacheKey)
     */
    function apps_cache_get_key(string $cacheKey = 'default', string|null $group = null)
    {
        try {
            $logger = apps_log_channel("app_cache");

            // Prefix key by APP_NAME to separate between apps/DB
            $prefix = Str::slug(env('APP_NAME', 'app'));
            $appliedKey = $prefix . ":" . $cacheKey;

            // If no group, return key with app name prefix
            if (blank($group)) {
                return $appliedKey;
            }

            /**
             * Add to data cache list of app
             */
            $app_cache_key = md5("app_data_cache_list");
            $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : [];
            $groupSlug = Str::slug($group);
            $cache_list[$groupSlug] = true; // Save group name (slug) to app cache management list
            Cache::forever($app_cache_key, $cache_list);

            /* Get list of keys in group, use slug to avoid special characters */
            $groupName = "group_" . $groupSlug;
            $groupCacheKey = md5($groupName);
            $groupData = json_decode(Cache::get($groupCacheKey, '[]'), true) ?: [];

            /* Use FIFO queue to store data, limit max 100 keys */
            /* When reaching limit, the first element will be deleted (FIFO - First In First Out) */
            $MAX_ITEM = 100;
            if (!in_array($appliedKey, $groupData)) {
                if (count($groupData) >= $MAX_ITEM) {
                    Cache::forget($groupData[0]); // Delete first element if reaching limit
                    array_shift($groupData);
                }
                array_push($groupData, $appliedKey); // Add new element at end of array
                Cache::forever($groupCacheKey, apps_json_encode($groupData));
            }

            /* Return key with app name prefix */
            return $appliedKey;
        } catch (\Throwable $th) {
            /* Log error if exception occurs */
            Log::channel($logger)->error("Get data error at: " . $cacheKey . ", " . $group);
            Log::channel($logger)->error($th->getMessage());
            return Str::slug(env('APP_NAME')) . ":" . 'default';
        }
    }
}

if (!function_exists('apps_cache_store')) {
    /**
     * Save data to cache with ability to group by group for easy management and group-wise deletion.
     *
     * 🎯 OPTIMIZATION: Nếu đã có $appliedKey từ apps_cache_get_key(), truyền vào để tránh gọi lại.
     *
     * @param string $key    Unique cache key (can be appliedKey or original key).
     * @param mixed  $data   Data to save to cache.
     * @param int    $time   Cache expiration time (in seconds, default 1 hour).
     * @param string|null $group Cache group name to support group-wise deletion (optional).
     * @param bool $isAppliedKey If true, $key is already appliedKey, no need to call apps_cache_get_key().
     *
     * @example
     * // Way 1: Pass original key (calls apps_cache_get_key)
     * apps_cache_store('user_123', $userData, 3600, 'users');
     *
     * // Way 2: Pass appliedKey already obtained (OPTIMAL - avoid calling apps_cache_get_key twice)
     * $appliedKey = apps_cache_get_key('user_123', 'users');
     * // ... check cache ...
     * apps_cache_store($appliedKey, $userData, 3600, 'users', true); // ⚠️ STILL NEED TO PASS GROUP!
     *
     * @return void
     */
    function apps_cache_store(
        string $key = 'default',
        $data = '',
        $time = 60 * 60,
        string|null $group = null,
        bool $isAppliedKey = false
    ): string|null {
        try {
            $logger = apps_log_channel("app_cache");

            if ($isAppliedKey) {
                // If appliedKey already exists, just store data
                $cacheKey = $key;

                // If group exists, still need to register appliedKey in groupData
                if (!blank($group)) {
                    $groupSlug = Str::slug($group);

                    // Register group in app cache list
                    $app_cache_key = md5("app_data_cache_list");
                    $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : [];
                    $cache_list[$groupSlug] = true;
                    Cache::forever($app_cache_key, $cache_list);

                    // Add appliedKey to groupData
                    $groupName = "group_" . $groupSlug;
                    $groupCacheKey = md5($groupName);
                    $groupData = json_decode(Cache::get($groupCacheKey, '[]'), true) ?: [];

                    if (!in_array($cacheKey, $groupData)) {
                        $MAX_ITEM = 100;
                        if (count($groupData) >= $MAX_ITEM) {
                            Cache::forget($groupData[0]);
                            array_shift($groupData);
                        }
                        array_push($groupData, $cacheKey);
                        Cache::forever($groupCacheKey, apps_json_encode($groupData));
                    }
                }
            } else {
                // Old way: call apps_cache_get_key() to create key and register group
                $cacheKey = apps_cache_get_key($key, $group);
            }

            Cache::put($cacheKey, $data, $time);
            return $cacheKey;
        } catch (\Throwable $th) {
            Log::channel($logger)->error("Store data error at: " . $key . ", " . $group);
            Log::channel($logger)->error($th->getMessage());
            return null;
        }
    }
}

if (!function_exists('apps_cache_get')) {
    /**
     * Retrieve data from cache using the same key creation logic (with group/prefix support).
     * Use this function instead of calling cache()->get($key) directly to avoid key mismatch
     * when changing prefix mechanism (e.g., enabling prefix by APP_NAME).
     *
     * 🎯 OPTIMIZATION: If you already have $appliedKey from apps_cache_get_key(), pass it to avoid re-calling.
     *
     * @param string   $key         Cache key (can be appliedKey or original key).
     * @param mixed    $default     Default value if cache doesn't exist.
     * @param ?string  $group       Cache group name (only used when $isAppliedKey = false).
     * @param bool     $isAppliedKey If true, $key is already appliedKey, no need to call apps_cache_get_key().
     * @return mixed
     *
     * @example
     * // Way 1: Pass original key (calls apps_cache_get_key) - BACKWARD COMPATIBLE
     * $data = apps_cache_get('user_123', null, 'users');
     *
     * // Way 2: Pass appliedKey already obtained (OPTIMAL - avoid calling apps_cache_get_key)
     * $appliedKey = apps_cache_get_key('user_123', 'users');
     * $data = apps_cache_get($appliedKey, null, null, true);
     */
    function apps_cache_get(
        string $key = 'default',
        $default = null,
        ?string $group = null,
        bool $isAppliedKey = false
    ) {
        try {
            $logger = apps_log_channel("app_cache");

            $cacheKey = $isAppliedKey ? $key : apps_cache_get_key($key, $group);
            return Cache::get($cacheKey, $default);
        } catch (\Throwable $th) {
            Log::channel($logger)->error("Get data error at: " . $key . ", " . $group);
            Log::channel($logger)->error($th->getMessage());
            return $default;
        }
    }
}

if (!function_exists('apps_cache_get_caller_info')) {
    /**
     * Get caller information to trace root cause
     *
     * @return array
     */
    function apps_cache_get_caller_info(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // Skip this function itself and other helper functions
        $skipFunctions = ['apps_cache_flush', 'apps_cache_get_caller_info'];

        foreach ($trace as $index => $frame) {
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';

            // Find first caller that is not a helper function
            if (!in_array($function, $skipFunctions)) {
                return [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'class' => $class,
                    'function' => $function,
                    'method' => $class ? "{$class}::{$function}" : $function,
                ];
            }
        }

        // Fallback: get first frame with information
        if (isset($trace[2])) {
            $frame = $trace[2];
            return [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? '',
                'function' => $frame['function'] ?? 'unknown',
                'method' => isset($frame['class']) ? "{$frame['class']}::{$frame['function']}" : ($frame['function'] ?? 'unknown'),
            ];
        }

        return ['file' => 'unknown', 'line' => 0, 'method' => 'unknown'];
    }
}

if (!function_exists('apps_cache_flush')) {
    /**
     * Delete data from cache by key or by group.
     *
     * - If only $cacheKey is passed, the function will delete cache by specific key.
     * - If $group is passed, the function will delete all cache in that group and delete the group cache.
     *
     * 🎯 OPTIMIZATION: If you already have $appliedKey from apps_cache_get_key(), pass it to avoid re-calling.
     *
     * @param string|null $cacheKey  Cache key to delete (can be appliedKey or original key).
     * @param string|null $group     Cache group to delete entirely (optional).
     * @param bool $isAppliedKey     If true, $cacheKey is already appliedKey, no need to call apps_cache_get_key().
     *
     * @example
     * // Way 1: Pass original key (calls apps_cache_get_key) - BACKWARD COMPATIBLE
     * apps_cache_flush('user_123'); // Delete 1 key
     * apps_cache_flush(null, 'users'); // Delete entire 'users' group
     *
     * // Way 2: Pass appliedKey already obtained (OPTIMAL - avoid calling apps_cache_get_key)
     * $appliedKey = apps_cache_get_key('user_123', null);
     * apps_cache_flush($appliedKey, null, true); // ⚠️ MUST SET isAppliedKey = true
     *
     * @return void
     */
    function apps_cache_flush(
        string|null $cacheKey = 'default',
        string|null $group = null,
        bool $isAppliedKey = false
    ) {
        try {
            $logger = apps_log_channel("app_cache");

            // Get caller information to trace root cause
            $caller = apps_cache_get_caller_info();

            if (blank($group)) {
                // If no group, delete cache by specific key
                if (blank($cacheKey)) {
                    Log::channel($logger)->warning("Flush skipped: cacheKey is blank");
                    return;
                }

                // Calculate applied key with prefix to delete accurately
                $appliedKey = $isAppliedKey ? $cacheKey : apps_cache_get_key($cacheKey, null);

                // Delete cache
                Cache::forget($appliedKey);

                // IMPORTANT: Delete key from all groups that contain it to avoid memory leak
                // and ensure groupData is in sync with actual cache
                $app_cache_key = md5("app_data_cache_list");
                $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : [];

                foreach ($cache_list as $groupSlug => $flag) {
                    $groupName = "group_" . $groupSlug;
                    $groupCacheKey = md5($groupName);

                    if (!Cache::has($groupCacheKey)) {
                        continue;
                    }

                    $groupData = json_decode(Cache::get($groupCacheKey), true) ?? [];

                    // Find and remove appliedKey from groupData
                    $keyIndex = array_search($appliedKey, $groupData, true);
                    if ($keyIndex !== false) {
                        unset($groupData[$keyIndex]);
                        $groupData = array_values($groupData); // Re-index array

                        if (count($groupData) > 0) {
                            Cache::forever($groupCacheKey, apps_json_encode($groupData));
                        } else {
                            // If group is empty, delete group cache key
                            Cache::forget($groupCacheKey);
                        }

                        Log::channel($logger)->debug("Removed key from group", [
                            'applied_key' => $appliedKey,
                            'group' => $groupSlug
                        ]);
                    }
                }

                Log::channel($logger)->info("Flushed cached data", [
                    'original_key' => $cacheKey,
                    'applied_key' => $appliedKey,
                    'caller' => $caller['method'] ?? 'unknown',
                    'file' => basename($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0)
                ]);
            } else {
                // Delete all cache in group
                $groupSlug = Str::slug($group);
                $groupCacheKey = md5("group_" . $groupSlug);

                if (!Cache::has($groupCacheKey)) {
                    // Group not yet created or already deleted - this is normal
                    // Don't log to avoid spam when Observer is triggered multiple times
                    // (example: bulk update ProviderForm will trigger Observer multiple times)
                    return;
                }

                Log::channel($logger)->info("Flushing cached data with group: $group", [
                    'group' => $group,
                    'caller' => $caller['method'] ?? 'unknown',
                    'file' => basename($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0)
                ]);

                $groupData = json_decode(Cache::get($groupCacheKey), true) ?? [];

                if (blank($groupData) || count($groupData) === 0) {
                    Log::channel($logger)->debug("Group data is empty");
                    Cache::forget($groupCacheKey); // Xóa group key ngay cả khi không có data
                    return;
                }

                // Delete each cache key in group
                foreach ($groupData as $appliedKey) {
                    Cache::forget($appliedKey);
                    Log::channel($logger)->debug("Flushed cached data: $appliedKey");
                }

                // Delete group cache key after deleting all child cache
                Cache::forget($groupCacheKey);
                Log::channel($logger)->info("Flushed group cache", [
                    'group' => $group,
                    'group_cache_key' => $groupCacheKey,
                    'total_keys' => count($groupData),
                    'caller' => $caller['method'] ?? 'unknown',
                    'file' => basename($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0)
                ]);
            }
        } catch (\Throwable $th) {
            Log::channel($logger)->error("Flush data error", [
                'cacheKey' => $cacheKey,
                'group' => $group,
                'isAppliedKey' => $isAppliedKey,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
        }
    }
}

if (!function_exists('apps_cache_reset')) {
    /**
     * Reset all application cache by deleting all saved cache groups.
     *
     * - Get list of saved cache groups.
     * - Loop through each group and call `apps_cache_flush()` to delete all cache in the group.
     * - Delete the cache management list after completion.
     * - Log the deletion process for tracking.
     *
     * @return void
     */
    function apps_cache_reset()
    {
        try {
            $logger = apps_log_channel("app_cache");

            $app_cache_key = md5("app_data_cache_list");
            $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : []; // Get list of groups
            foreach ($cache_list as $key => $flag) {
                Log::channel($logger)->info("- Delete cache group " . $key);
                apps_cache_flush(null, $key); // Filter each group and delete all cache keys in it
            }
            Cache::forget($app_cache_key); // Delete cache management file
        } catch (\Throwable $th) {
            Log::channel($logger)->error("Reset cache data error");
            Log::channel($logger)->error($th->getMessage());
        }
    }
}

if (!function_exists('apps_cache_debug')) {
    /**
     * Debug cache state and optionally log the cached value.
     *
     * @param string $key
     * @param string|null $channel
     * @param bool $showValue
     * @return void
     */
    function apps_cache_debug(string $key, string $channel = 'daily', bool $showValue = true): void
    {
        $logger = apps_log_channel("app_cache");

        $appliedKey = apps_cache_get_key($key, null);
        $status = Cache::has($appliedKey) ? 'HIT' : 'MISS';

        $logData = [
            'cache_key' => $key,
            'applied_cache_key' => $appliedKey,
            'status' => $status,
        ];

        if ($showValue && $status === 'HIT') {
            $logData['value'] = apps_cache_get($key, null, null, false);
        }

        Log::channel($logger)->info('[Cache Debug]', $logData);
    }
}
#endregion

if (!function_exists('apps_is_valid_timestamp')) {
    /**
     * Check if a value is a valid Unix timestamp.
     *
     * Validates that the value is a numeric string representing an integer
     * within PHP's integer range limits.
     *
     * @param mixed $timestamp Value to check
     * @return bool True if valid timestamp, false otherwise
     */
    function apps_is_valid_timestamp($timestamp)
    {
        return ((string) (int) $timestamp === $timestamp)
            && ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= ~PHP_INT_MAX);
    }
}

if (!function_exists('apps_json_encode')) {
    /**
     * Encode data to JSON string with enhanced options.
     *
     * Provides JSON encoding with Unicode and slash preservation, optional
     * pretty printing, and error handling. By default throws exceptions on errors.
     *
     * @param array|object|null $data Data to encode
     * @param bool $pretty If true, format JSON with indentation (multi-line)
     * @param bool $withEol If true, append newline character at the end
     * @param bool $throwOnError If true, throw exception on encoding failure
     * @return string JSON-encoded string
     * @throws \JsonException When encoding fails and $throwOnError is true
     */
    function apps_json_encode(
        array|object|null $data,
        bool $pretty = false,
        bool $withEol = false,
        bool $throwOnError = true // Default is true, except when there's a clear reason not to use
    ): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        if ($throwOnError) {
            $flags |= JSON_THROW_ON_ERROR;
        }

        $json = json_encode($data, $flags);

        if (!$throwOnError && $json === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }

        return $withEol ? $json . PHP_EOL : $json;
    }
}

if (!function_exists('apps_num2alpha')) {
    /**
     * Convert a numeric index to Excel-style column letter (A, B, C, ..., Z, AA, AB, ...).
     *
     * Converts a zero-based numeric index to corresponding column letter(s)
     * as used in spreadsheet applications (e.g., 0 → A, 25 → Z, 26 → AA).
     *
     * @param int $n Zero-based numeric index
     * @return string Column letter(s) (e.g., 'A', 'Z', 'AA', 'AB')
     */
    function apps_num2alpha($n)
    {
        $r = '';
        while ($n >= 0) {
            $r = chr($n % 26 + 65) . $r;
            $n = intval($n / 26) - 1;
        }
        return $r;
    }
}

if (!function_exists('apps_array_get_first_non_empty')) {
    /**
     * Get value of the first key with non-empty value in the array.
     * @param array $array Source array
     * @param array $keys List of keys in priority order
     * @param mixed $default Return value if no valid key found
     * @return mixed
     */
    function apps_array_get_first_non_empty(array $array, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            $value = Arr::get($array, $key);
            if (!is_null($value) && trim((string) $value) !== '') {
                return $value;
            }
        }
        return $default;
    }
}

if (!function_exists('apps_array_remove_null')) {
    /**
     * Recursively remove null and empty values from an array.
     *
     * Removes null, empty strings, and empty arrays from the data structure.
     * Preserves empty arrays if they are part of the structure (only removes
     * values, not empty array containers). Optionally resets numeric keys.
     *
     * Use this function before calling Arr::get to avoid errors when encountering
     * keys that exist but have null values.
     *
     * @param mixed $item Input data (array or scalar)
     * @param bool $resetNumericKeys If true, re-index numeric arrays after filtering
     * @return mixed Filtered data with null/empty values removed
     */
    function apps_array_remove_null($item, bool $resetNumericKeys = true)
    {
        if (!is_array($item))
            return $item;

        $filtered = [];

        foreach ($item as $key => $value) {
            $value = is_array($value)
                ? apps_array_remove_null($value, $resetNumericKeys)
                : $value;

            if ($value === null || $value === '' || $value === [] || $value === "") {
                continue;
            }

            $filtered[$key] = $value; // always keep $key => $value
        }

        return $resetNumericKeys && array_is_list($filtered)
            ? array_values($filtered)
            : $filtered; // reset key if numeric array and flag enabled
    }
}

if (!function_exists('apps_extract_additional_data')) {
    /**
     * Normalize additional data before saving to DB.
     * - Keep only fields not in table schema.
     * - Support schema caching to reduce queries.
     *
     * @param  array   $data    Source data (reference & update directly).
     * @param  string  $schema  Table name (default: leads).
     * @param  mixed   $additionalDataIgnore Fields not needed in additional_data
     * @return array   Array of additional data (normalized).
     */
    function apps_extract_additional_data(
        array $data,
        string $schema = "leads",
        ?array $additionalDataIgnore = null // các field không cần lưu vào additional_data
    ): array {
        $additionalDataIgnore ??= [
            'hub_uuid',
            'pull_id',
            'user_id',
            'leadsource',
            'lead_source',
            'logger',
            'custom_action',
            'automation',
            'leadgen_at_timestamp',
            'exec_mode',
            'time',
            'Hub Data Request',
            'Hub Response',
            'Hub Response Text',
            'access_token',
            'refresh_token',
            'token_expires_at',
            'additional_data',
            'leadgen_notification',
            'leadgen_notification_spreadsheet'
        ]; // assign default data if not passed

        // Get list of columns from cache or DB
        $cacheKey = "schema_$schema";
        $cols = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($schema) {
            return DB::connection()->getSchemaBuilder()->getColumnListing($schema);
        });

        $additionalData = [];
        // Analyze and collect additional data, 3 processing methods: array_walk, foreach or laravel (Collection + reject)
        // C1: $additionalData = collect($data)
        //     ->reject(fn($value, $key) => in_array($key, $cols, true) || in_array($key, $additionalDataIgnore, true))
        //     ->toArray();
        // C2: array_walk($data, function ($value, $key) use (&$additionalData, $cols, $additionalDataIgnore) {
        //     if (!in_array($key, $cols, true) && !in_array($key, $additionalDataIgnore, true)) {
        //         $additionalData[$key] = $value;
        //     }
        // });

        $colsMap = array_flip($cols);
        $ignoreMap = array_flip($additionalDataIgnore);
        foreach ($data as $key => $value) {
            if (!isset($colsMap[$key]) && !isset($ignoreMap[$key])) {
                $additionalData[$key] = $value;
            }
        }

        return $additionalData ?? [];
    }
}

if (!function_exists('apps_as_array')) {
    /**
     * Ensure a value is an array, converting non-arrays to empty array.
     *
     * Type-safe helper to guarantee array type. Returns the value if it's
     * already an array, otherwise returns an empty array.
     *
     * @param mixed $value Value to convert
     * @return array Original array or empty array
     */
    function apps_as_array($value): array
    {
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('apps_get_image_url_webp')) {
    /**
     * Return WebP version URL of image if corresponding .webp file exists on server.
     *
     * How it works:
     * - Only applies to images with extensions: jpg|jpeg|png.
     * - Parse URL to get path (exclude query/fragment), create .webp path corresponding to original image.
     * - Check existence of .webp file in public directory (public_path).
     * - If exists: return .webp URL but keep original query/fragment (if any).
     * - If not exists: return original image URL.
     *
     * Performance optimization:
     * - Use memoization by originalUrl to avoid repeating I/O operations (File::exists) in same request lifecycle.
     *
     * Note:
     * - Function does not convert image to WebP, only checks existence of existing .webp file.
     * - If URL is empty or has no valid path → return null/corresponding original URL.
     * - Behavior depends on mapping between URL and public_path. Need unified configuration for static URL ↔ public directory.
     *
     * @param string $originalUrl Original image URL (may include query/fragment)
     * @return string|null URL .webp if exists, otherwise original URL; null when parameter empty
     */
    function apps_get_image_url_webp($originalUrl): string|null
    {
        static $memo = [];

        if (empty($originalUrl)) {
            return null;
        }

        if (isset($memo[$originalUrl])) {
            return $memo[$originalUrl];
        }

        // Get safe path from URL (exclude query/fragment), avoid File::extension on full URL
        $path = (string) (parse_url($originalUrl, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return $memo[$originalUrl] = $originalUrl;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return $memo[$originalUrl] = $originalUrl;
        }

        // Create corresponding .webp path by path, keep original domain/query of URL when returning
        $webpPath = substr($path, 0, -strlen($ext)) . 'webp';
        $filePath = public_path(ltrim($webpPath, '/'));

        if (!File::exists($filePath)) {
            return $memo[$originalUrl] = $originalUrl;
        }

        // Replace extension in original URL (only at end), keep original query/fragment
        $webpUrl = preg_replace('/\.' . preg_quote($ext, '/') . '(\?.*)?$/i', '.webp$1', $originalUrl);

        return $memo[$originalUrl] = ($webpUrl ?: $originalUrl);
    }
}

if (!function_exists('apps_leadgen_prepare_data')) {
    /**
     * Prepare and normalize lead generation data for database storage.
     *
     * Performs comprehensive data normalization including:
     * - Removing null/empty values
     * - Trimming all string values
     * - Converting keys to lowercase with underscores
     * - Mapping custom fields using provided mappings
     * - Standardizing common fields (phone, email, name, address, etc.)
     * - Extracting province/city/district from various input formats
     *
     * @param array $lead Raw lead data from form/webhook
     * @param array|null $mappings Custom field mappings for database columns
     * @param string $logger Log channel name
     * @return array Normalized lead data ready for database insertion
     */
    function apps_leadgen_prepare_data($lead, $mappings = null, $logger = 'daily')
    {
        Log::channel($logger)->info("==========> " . __FUNCTION__ . " helper is running");

        #region pre processing data
        $lead = apps_array_remove_null($lead); // remove elements having NULL value from multidimentional array

        array_walk_recursive($lead, function (&$arrValue, $arrKey) {
            if (!blank($arrValue)):
                $arrValue = trim($arrValue); // $lead = array_map('trim', $lead); // trim function converts null value to ""
            endif;
        });
        $origin = collect($lead)->toArray();

        ## convert all array keys to standard format w/ underscore instead of special characters
        $lead = array_combine(
            array_map(function ($key) {
                return strtolower(Str::slug($key, '_'));
            }, array_keys($lead)),
            array_values($lead)
        );
        #endregion

        #region hub_step fields mapping
        if (!blank($mappings)) { // hub_step fields mapping
            try {
                // Log::channel($logger)->info('hub_step fields mapping to database', $mappings);
                foreach ($mappings as $mapping_key => $_mappings) {
                    // Log::channel($logger)->info('mapping_key', (array) $mapping_key);
                    if (count($_mappings)) {
                        foreach ($_mappings as $__mapping) {
                            /**
                             * chú ý: tên cột của spreadsheet không chấp nhận dấu _ và bất kỳ ký tự ngoài các chữ alphebet
                             * eg: "1009385287145182|số_điện_thoại" or "số_điện_thoại or sđt_của_cha_mẹ:"
                             */
                            $__mapping_key = strtolower(Str::slug($__mapping['key'], '_')); # <<<<< chú ý gạch chân ghi làm việc với gg spreadsheet.
                            $__mapping_key = isset(explode("|", $__mapping_key)[1]) ? explode("|", $__mapping_key)[1] : $__mapping_key;
                            // Log::channel($logger)->info('__mapping_key slug', (array) $__mapping_key);

                            if (
                                (isset($lead['providerformid']) && $lead['providerformid']) ||
                                (isset($lead['provider_form_id']) && $lead['provider_form_id'])
                            ) {
                                #code
                            }

                            /**
                             * chú ý: chưa xử lý nếu mapping multiple keys
                             * eg: mapping {ten, ho ten, ten day du} > fullname
                             */
                            if (!blank(Arr::get($lead, $__mapping_key)) && $svalue = Arr::get($lead, $__mapping_key)) {
                                $lead[strtolower($mapping_key)] = is_array($svalue) ? json_encode($svalue) : "{$svalue}";
                            }
                        }
                    }
                }
            } catch (\Throwable $th) {
                Log::channel($logger)->error($th->getMessage());
                Log::channel($logger)->error($th->getTraceAsString());
                // DO NOT THROW
            }
        }
        #endregion

        $lead['dealer'] = apps_array_get_first_non_empty($lead, [
            'dealer',      // prioritize keeping original key for toyota_crm
            'showroom'
        ], null);

        $lead['dealer_id'] = apps_array_get_first_non_empty($lead, [
            'dealer_id',
            'showroom_id'
        ], null);

        $emailRaw = apps_array_get_first_non_empty($lead, [
            'email',
            'your_email',
            'email_cua_ban_la_gi',
            'dia_chi_email_cua_ban_la_gi',
            'user_email',
            'what_is_your_email_address',
        ], null);
        $lead['email'] = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : null;

        $phoneRaw = apps_array_get_first_non_empty($lead, [
            'phone',
            'phone_number',
            'so_dien_thoai',
            'mobile',
            'so_dien_thoai_cua_ban_la_gi',
            'hotline',
            'so_dien_thoai_dang_ki_lai_thu_cua_ban_la_gi',
            'what_is_your_phone_number',
            'your_phone',
            'user_phone',
            'nhap_so_dien_thoai',
        ], null);
        $lead['phone'] = $lead['mobile'] = $lead['phone_number'] = $lead['phonenumber'] = $lead['so_dien_thoai'] = apps_phone_convert($phoneRaw);


        $lead['name'] = $lead['fullname'] = apps_array_get_first_non_empty($lead, [
            'name',
            'fullname',
            'tendayducuabanlagi',
            'hotencuabanlagi',
            'clientname',
            'whatisyourfullname',
            'tendaydu',
            'ten',
            'yourname',
            'hovaten',
            'hoten',
            'full_name',
            'first_name',
            'firstname',
            'ho_va_ten',
            'ho_ten',
            'client_name',
            'customer_name',
            'ten_day_du',
            'phone',   // use phone if name is null, must place at end of condition
            'email'    // use email if name is null, must place at end of condition
        ], null);
        $lead['model'] = $lead['dong_xe'] = $data['mau_xe_ma_ban_quan_tam'] = $lead['dong_xe_quan_tam'] = $lead['dong_xe_ban_quan_tam'] = apps_array_get_first_non_empty($lead, [
            'model',
            'dong_xe',
            'dong_xe_quan_tam',
            'dong_xe_ban_quan_tam',
            'dong_xe_muon_lai_thu',
            'mau_xe_ma_ban_quan_tam',
            'chon_dong_xe',
        ], null);
        $lead['nhu_cau'] = $lead['nhu_cau_cua_ban'] = $lead['notes'] = $lead['description'] = apps_array_get_first_non_empty($lead, [
            'nhu_cau',
            'nhu_cau_cua_ban',
            'yeu_cau',
            'quy_khach_hien_dang_co_nhu_cau',
            'quy_khach_hay_chon_nhu_cau_ve_dong_xe',
        ], null);

        $lead['city'] = $lead['province'] = $lead['thanh_pho'] = $lead['tinh_thanh_pho'] = $lead['tinh_thanh'] = Str::limit(
            apps_array_get_first_non_empty($lead, [
                'city',
                'province',
                'thanh_pho',
                'tinh_thanh',
                'tinh_thanh_pho',
                'tinhthanh_pho',
                'ban_song_tai_tinh_thanh_pho_nao',
                'ban_dang_song_o_tinh_thanh_pho_nao',
                'ban_song_tai_tinhthanh_pho_nao',
                'ban_song_o_tinhthanh_pho_nao',
                'quy_khach_hay_chon_noi_sinh_song',
                'chon_dia_diem',
                'chon_dai_ly',
                'dai_ly',
            ], null),
            100
        ); // limit 100 characters, when user enters too much;
        // if (blank($lead['city']) and !blank($lead['dealer'])) {
        //     preg_match('#\((.*?)\)#', $lead['dealer'], $provinceArr); // extract string from (Bắc Ninh) ĐL Bắc Ninh_TBN
        //     if (!blank($provinceArr)) {
        //         $lead['city'] = $lead['province'] = $lead['thanh_pho'] = $lead['tinh_thanh_pho'] = $lead['tinh_thanh'] = $provinceArr[1]; // Bắc Ninh
        //     }
        // }

        $lead['district'] = $lead['quan_huyen'] = apps_array_get_first_non_empty($lead, [
            'district',
            'quan_huyen',
        ], null);

        $lead['ward'] = $lead['phuong_xa'] = apps_array_get_first_non_empty($lead, [
            'ward',
            'phuong_xa',
        ], null);

        $lead['dia_chi'] = $lead['address1'] = $lead['address2'] = apps_array_get_first_non_empty($lead, [
            'address',
            'address1',
            'address2',
            'dia_chi',
            'so_nha_va_ten_duong',
            'street_address'
        ], null);

        $lead['notes'] = $lead['description'] = apps_array_get_first_non_empty($lead, [
            'content',
            'description',
            'comment',
            'notes',
            'noidung',
            'model',
        ], null);

        $lead['identity_card'] = apps_array_get_first_non_empty($lead, [
            'idcard',
            'identity_card',
            'cmnd',
            'cccd',
        ], null);

        $lead['tax_code'] = $lead['mst'] = apps_array_get_first_non_empty($lead, [
            'tax_code',
            'mst',
            'tax',
        ], null);

        Log::channel($logger)->info("==========> " . __FUNCTION__, array_change_key_case(array_merge($origin, $lead), CASE_LOWER));
        return array_change_key_case(array_merge($origin, $lead), CASE_LOWER);
    }
}
