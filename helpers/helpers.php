<?php

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
    // Recursive apps_sanitize_for_xml.
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
     * Removes invalid XML
     *
     * https://stackoverflow.com/questions/3466035/how-to-skip-invalid-characters-in-xml-file-using-php
     *
     * @access public
     * @param string $value
     * @return string
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
     * @param  array   $data                Dữ liệu đầu vào, đã parse thành mảng key => value
     * @param  array   $mappings            Cấu hình mappings từ người dùng
     * @param  string  $logger              Tên channel log
     * @param  string  $mode                Chế độ gộp:
     *                                     - 'coalesce' (mặc định): lấy giá trị đầu tiên không rỗng
     *                                     - 'concat': nối tất cả giá trị (unique + giữ thứ tự)
     * @param  bool    $removeEmptyValues   Nếu TRUE → bỏ các key có giá trị null hoặc rỗng
     * @return array                        Mảng key => value sau khi mapping
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
        apps_log($logger)->info('hub_step fields mapping to spreadsheet', (array) $mappings);

        $values_mappings = [];

        foreach ($mappings as $mapping_key => $_mappings) {
            /* reset giá trị trước mỗi vòng lặp để tránh dính data cũ */
            $values_mappings[$mapping_key] = null;
            $bucket = [];
            apps_log($logger)->info('mapping_key', (array) $mapping_key);

            foreach ((array) $_mappings as $__mapping) {
                if (!isset($__mapping['key'])) continue;

                /* tách theo '|' TRƯỚC, rồi mới slug (fix bug thứ tự cũ) */
                /**
                 * chú ý: tên cột của spreadsheet không chấp nhận dấu _ và bất kỳ ký tự ngoài các chữ alphabet
                 * ví dụ: "1009385287145182|số_điện_thoại" hoặc "số_điện_thoại" hoặc "sđt_của_cha_mẹ:"
                 * tách theo '|' TRƯỚC, rồi mới slug (fix bug thứ tự cũ)
                 */
                $parts = explode('|', (string) $__mapping['key'], 2);
                $rawKey = $parts[1] ?? $parts[0];

                /* chuẩn hóa key: ascii + slug + bỏ separator */
                $__mapping_key = Str::slug(Str::ascii($rawKey), '');
                apps_log($logger)->info('__mapping_key slug', (array) $__mapping_key);

                /* lấy giá trị từ input */
                $raw = Arr::get($data, $__mapping_key);
                apps_log($logger)->info('__raw value', (array) $raw);

                /* chuẩn hóa value: trim, bỏ rỗng, hỗ trợ mảng */
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
                apps_log($logger)->warning('hub_step fields mapping data is empty');
            }
        }

        /* Lọc bỏ các giá trị null hoặc chuỗi rỗng nếu người dùng yêu cầu */
        if ($removeEmptyValues) {
            $values_mappings = array_filter(
                $values_mappings,
                fn($value) => !is_null($value) && $value !== ''
            );
        }

        apps_log($logger)->info('hub_step fields mapping data to spreadsheet', $values_mappings);

        return $values_mappings;
    }
}
if (!function_exists('apps_gsheet_header_row')) {
    /**
     * Đọc DÒNG HEADER (dòng 1) của một tab — chỉ tải đúng 1 dòng.
     *
     * [Port từ v7.x — commit 0074a20, 2ea8dff]
     *
     * ## Vì sao có hàm này (sự cố THẬT 26–27/09/2026, ghi nhận trên v7.x)
     *
     * Trước đây header lấy bằng `->get()->pull(0)`: `get()` tải TOÀN BỘ tab rồi mới lấy dòng đầu.
     * Với một sheet 41.000 dòng của khách, `values:batchGet?ranges=Sheet1` không trả kịp ⇒ 395
     * lần timeout trong một ngày, mỗi lần chờ 60s. Trước khi có trần thời gian, chính nó treo vô
     * hạn và giết worker hàng đợi suốt 24,5 giờ, kéo 608 job ứ lại.
     *
     * Đây là lỗi TỰ NẶNG DẦN: tab càng nhiều dữ liệu càng chậm, nên không con số timeout nào là
     * "đủ" — phải thôi tải cả tab.
     *
     * ## Vì sao gọi thẳng service, thay vì ->range('1:1') cho gọn
     *
     * `Sheets` của gói bản 5.x/6.x bind bằng `singleton()` (cùng MỘT instance suốt tiến trình
     * worker, sống qua nhiều job), `$range` STICKY — `spreadsheet()`/`sheet()`/`sheetById()` đều
     * KHÔNG reset nó — và `append()` lại dùng chính `ranges()`. Nên nếu set `range('1:1')` mà vì bất
     * kỳ lý do gì nó không được trả về nguyên trạng, lượt ghi kế tiếp sẽ ghi vào đúng DÒNG HEADER.
     *
     * Hàm này vì vậy KHÔNG chạm `$range`: lấy service rồi gọi API trực tiếp. Đọc là đọc, ghi là
     * ghi, không dùng chung state.
     *
     * @param  array|string  $accessToken  token OAuth2
     * @param  string  $spreadsheetId  id bảng tính
     * @param  string  $sheetName  TÊN tab — lấy từ `->sheetById($id)->ranges()` để đúng cả khi
     *                             khách đổi tên tab, thay vì tin giá trị lưu trong cấu hình
     * @return array dòng header; mảng rỗng nếu tab rỗng
     */
    function apps_gsheet_header_row($accessToken, string $spreadsheetId, string $sheetName): array
    {
        // A1 notation: tên tab phải bọc nháy đơn (nhân đôi nháy đơn bên trong) — tab tên
        // "Data 2026" hay "Khach's" để trần thì Google trả "Unable to parse range".
        $quoted = "'" . str_replace("'", "''", $sheetName) . "'";

        $service = Sheets::setAccessToken($accessToken)->getService();

        // Trần thời gian RIÊNG cho lượt đọc này (mặc định chung 60 giây là vô nghĩa với 1 hàng).
        // Client DÙNG CHUNG với lượt GHI nên phải TRẢ LẠI nguyên trạng trong `finally`.
        $client = $service->getClient();
        $previousHttpClient = $client->getHttpClient();

        // `getHttpClient()` không hứa trả GuzzleHttp\Client — mà `getConfig()` chỉ Guzzle mới có.
        // Không phải Guzzle ⇒ bỏ qua bước hạ trần, giữ nguyên đường cũ.
        $canScopeTimeout = $previousHttpClient instanceof \GuzzleHttp\Client;

        try {
            if ($canScopeTimeout) {
                $client->setHttpClient(new \GuzzleHttp\Client([
                    'base_uri' => $previousHttpClient->getConfig('base_uri'),
                    'http_errors' => false,
                    'connect_timeout' => (int) config('google.http.connect_timeout', 10),
                    'timeout' => (int) config('google.http.header_read_timeout', 8),
                ]));
            }

            $response = $service->spreadsheets_values
                ->batchGet($spreadsheetId, ['ranges' => $quoted . '!1:1']);
        } finally {
            if ($canScopeTimeout) {
                $client->setHttpClient($previousHttpClient);
            }
        }

        $valueRanges = $response->getValueRanges();

        if (blank($valueRanges)) {
            return [];
        }

        $values = $valueRanges[0]->getValues();

        return is_array($values) && isset($values[0]) && is_array($values[0]) ? $values[0] : [];
    }
}
if (!function_exists('apps_gsheet_append_range')) {
    /**
     * Range A1 để `append()` chỉ dò bảng trong cột A (tên tab bọc nháy đơn, nhân đôi nháy đơn bên trong).
     * [Port từ v7.x — commit eccf4b9]
     */
    function apps_gsheet_append_range(string $tab): string
    {
        return "'" . str_replace("'", "''", $tab) . "'!A:A";
    }
}
if (!function_exists('apps_gsheet_range_starts_at_column_a')) {
    /**
     * `updatedRange` Google trả về (vd "'Raw Lead'!A3605:M3605") có bắt đầu ở cột A không.
     * [Port từ v7.x — commit eccf4b9]
     */
    function apps_gsheet_range_starts_at_column_a(?string $updatedRange): bool
    {
        return (bool) preg_match('/!A\d+(?::[A-Z]+\d+)?$/', (string) $updatedRange);
    }
}
if (!function_exists('apps_google_sheet')) {

    /**
     * Google spreadsheet function "apps_google_sheet"
     * @param array $spreadsheetData
     * @param array $spreadsheet {"spreadsheet":{"id":"1lFaPiWTQnterK-otcjE0m3v___7AkYA5AbxZCWXEAWQ","name":"[ZALO] TMV - Digimind"},"sheet":{"id":0,"name":"Sheet1"}}
     * @param string|'oauth2' $credentialsType enum('service_account', 'oauth2)
     * @param string|null $credentialsFile json credential file
     * @param array|null $connection Source connection
     * @param string|null $logger
     * @param string|null $with_keys Insert with specified Spreadsheet cols name or not
     * @param array|null  $mappings : using for mapping spreadsheet cols name & leadgen formmeta

     * @return bool|string|null
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
        $cache_headers = true, // cho phép bật/tắt cache
        $cache_ttl = 300,      // TTL mặc định: 300s (5 phút)
        $cache_force_refresh = false // cho phép bỏ qua cache
    ) {
        try {
            apps_log($logger)->info("==========> " . __FUNCTION__ . " helper is running");
            apps_log($logger)->info(__FUNCTION__ . ": spreadsheet variables", (array) $spreadsheet);
            // apps_log($logger)->info("Spreadsheet data captured", (array) $spreadsheetData);

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
            // apps_log($logger)->info('[GSheet::Spreadsheet Info]', [
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
                    // apps_log($logger)->info("Starting with credential '{$credentialsType}'");

                    $connection = $connection['connection'];
                    // apps_log($logger)->info("Connection captured", $connection);
                    // [Port từ v7.x — commit 34edf15] OAuth access token PHẢI kèm expires_in/created.
                    // Nếu chỉ có access_token + refresh_token (như code cũ), Google\Client::isAccessTokenExpired()
                    // LUÔN trả true → Sheets::setAccessToken() của gói gọi fetchAccessTokenWithRefreshToken()
                    // MỖI lần → mỗi ghi Sheet phát sinh 1 request thừa tới oauth2.googleapis.com/token. Khi
                    // sync ồ ạt (nhiều worker) → Google throttle ở tầng TLS (sslv3 alert handshake failure)
                    // → ghi Sheet fail rải rác. Fix: refresh ĐÚNG MỘT LẦN rồi CACHE token đầy đủ theo
                    // refresh_token, tái dùng tới gần hết hạn. Fallback về hành vi cũ nếu thiếu
                    // refresh_token / config / refresh lỗi → không bao giờ tệ hơn trước.
                    $refreshToken = (string) ($connection['provider_exchange_refresh_token'] ?? '');
                    $legacyToken = [
                        'access_token' => $connection['provider_exchange_token'] ?? null,
                        'refresh_token' => $refreshToken,
                    ];

                    if (blank($refreshToken)) {
                        $accessToken = $legacyToken; // không refresh được → giữ nguyên như cũ
                    } else {
                        $oauthCacheKey = 'apps_google_sheet:oauth2_token:' . sha1($refreshToken);
                        $accessToken = Cache::get($oauthCacheKey);

                        if (blank($accessToken) || blank($accessToken['access_token'] ?? null)) {
                            try {
                                $oauthClient = new Client();
                                $oauthClient->setClientId((string) config('google.client_id'));
                                $oauthClient->setClientSecret((string) config('google.client_secret'));
                                $fresh = $oauthClient->fetchAccessTokenWithRefreshToken($refreshToken);

                                if (empty($fresh['error']) && !empty($fresh['access_token'])) {
                                    // Google thường KHÔNG trả lại refresh_token khi refresh → giữ token gốc.
                                    $fresh['refresh_token'] = $fresh['refresh_token'] ?? $refreshToken;
                                    // created bảo đảm có để isAccessTokenExpired() tính đúng hạn.
                                    $fresh['created'] = $fresh['created'] ?? time();
                                    // buffer 120s: evict sớm để không dùng token sát hạn.
                                    $oauthTtl = max(60, (int) ($fresh['expires_in'] ?? 3600) - 120);
                                    Cache::put($oauthCacheKey, $fresh, $oauthTtl);
                                    $accessToken = $fresh;
                                } else {
                                    apps_log($logger)->warning(__FUNCTION__ . ": oauth2 refresh returned no access_token, fallback to legacy token", (array) ($fresh['error'] ?? 'unknown'));
                                    $accessToken = $legacyToken;
                                }
                            } catch (\Throwable $e) {
                                apps_log($logger)->warning(__FUNCTION__ . ": oauth2 token refresh/cache failed, fallback to legacy token: " . $e->getMessage());
                                $accessToken = $legacyToken;
                            }
                        }
                    }
                    #endregion
                } else { // using non-laravel style
                    #region Setup Google Client & Service Account
                    $client = new Client();
                    $client->setApplicationName(env('APP_NAME'));
                    $client->setScopes([
                        \Google\Service\Sheets::SPREADSHEETS,
                        \Google\Service\Sheets::DRIVE_FILE,
                        // \Google\Service\Sheets::DRIVE, // (chỉ bật tạm khi cần token) ứng dụng cần có security certificates theo tiêu chuẩn google (khá khó), See, edit, create, and delete all of your Google Drive files
                        // \Google\Service\Localservices::ADWORDS,
                        // \Google\Service\Script::SCRIPT_PROJECTS
                    ]);
                    $client->setAccessType('offline');
                    #endregion

                    #region Use a "Service Account" to connection and spreadsheet process data
                    // apps_log($logger)->info("Starting with credential '{$credentialsType}'");

                    #region Setup Google Credentials
                    if (!$credentialsFile)
                        $credentialsFile = config_path('google-pull.vn-service-account-110095136248877193658.json'); // Service Account

                    $client->setAuthConfig($credentialsFile); // use environment variable: putenv("GOOGLE_APPLICATION_CREDENTIALS=$credentialsFile"); $client->useApplicationDefaultCredentials();
                    #endregion

                    $tokenPath = storage_path('app/google-service-account-tokens.json');
                    if (file_exists($tokenPath)) {
                        $accessToken = json_decode(file_get_contents($tokenPath), true);
                        $client->setAccessToken($accessToken);
                    }
                    if ($client->isAccessTokenExpired()) {
                        apps_log($logger)->warning(__FUNCTION__, (array) "Token is expired, it will be automatically renew rightnow");
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
                apps_log($logger)->error(__FUNCTION__ . ": " . $th->getMessage());
                throw $th; // throw errors
            }
            #endregion

            #region Spreadsheet process data
            $values = $values_mappings = [];

            // [Port từ v7.x — commit 13058ea] Khai báo ở scope NGOÀI: `$headers` chỉ được gán trong
            // nhánh `$with_keys`, nhưng bước sắp cột trước `append()` (cuối hàm) đọc nó ở scope này.
            $headers = [];

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
                    'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),      // vẫn giữ 0 nếu có, vì với spreadsheet thì sheet đầu tiên có thể có ID value = 0
                    'sheet_name' => Arr::get($spreadsheet, 'sheet.name'),
                ]));

                // apps_log($logger)->info('[GSheet::CacheKey Usage]', [
                //     'cache_key' => $cacheKey,
                //     'spreadsheet_id' => $spreadsheet['spreadsheet']['id'],
                //     'sheet_id' => $spreadsheet['sheet']['id'],
                //     'sheet_name' => $spreadsheet['sheet']['name'],
                //     'form_id' => $spreadsheetData['formid'] ?? 'n/a',
                //     'form_name' => $spreadsheetData['formname'] ?? 'n/a',
                // ]);
                #endregion

                // [Port từ v7.x — commit 0074a20, 417457d, a7f8191] ĐỌC TƯƠI LÀ ĐƯỜNG CHÍNH, chỉ đọc
                // DÒNG 1 (không tải cả tab). Cache 300s + "header tốt gần nhất" (LKG) 7 ngày hạ xuống
                // làm LƯỚI ĐỠ khi đọc tươi lỗi (quota 429, mạng, timeout) — có ghi log phân biệt rõ.
                //
                // Vì sao: (1) tải cả tab bằng `->get()->pull(0)` là lỗi tự nặng dần theo kích thước
                // sheet (41.000 dòng ⇒ timeout 60s hàng trăm lần/ngày). (2) Từ khi sắp cột trước
                // `append()` (cuối hàm), header cũ trong cache ⇒ dòng ghi lệch cột IM LẶNG, nên cache
                // không được là đường chính. (3) Sự cố DGM/Toyota 14/10: khi Google chậm kéo dài, cache
                // 300s hết hạn và không nạp lại được ⇒ lead rơi vào nhánh "không có header" và mất hẳn;
                // LKG 7 ngày đỡ đúng ca này.
                //
                // `$cache_force_refresh` nay không còn ý nghĩa (đường chính đã luôn tươi). Giữ tham số
                // để không phá chữ ký hàm; @deprecated.
                $headers = [];
                $headersFromCache = false;

                try {
                    // `sheetById()` để resolve TÊN tab từ id; `ranges()` trả tên đó khi `$range` rỗng.
                    // Riêng bản v6: `Sheets` của gói 5.x/6.x là `singleton()` (sống qua nhiều job trong
                    // cùng worker) và `apps_google_sheet_update_by_log()` đặt range mà không dọn ⇒ dọn
                    // `range('')` TRƯỚC khi đọc `ranges()`, nếu không sẽ đọc header theo range rò rỉ.
                    $client = Sheets::setAccessToken($accessToken)
                        ->spreadsheet($spreadsheet['spreadsheet']['id']) // đây là điểm làm thay đổi context state, cẩn thận nếu dùng không tường minh logic append data
                        ->sheetById($spreadsheet['sheet']['id'])
                        ->range('');

                    $fetched = apps_gsheet_header_row(
                        $accessToken,
                        $spreadsheet['spreadsheet']['id'],
                        (string) $client->ranges()
                    );

                    $headers = is_array($fetched) ? $fetched : collect($fetched)->toArray();
                } catch (\Throwable $e) {
                    // Quota (429), mạng, token — KHÔNG để cả lượt ghi chết vì một phép đọc phụ.
                    $headers = $cache_headers ? (array) Cache::get($cacheKey, []) : [];
                    $headersFromCache = !blank($headers);
                    $headersFromLastKnownGood = false;

                    if (blank($headers) && $cache_headers) {
                        $headers = (array) Cache::get($cacheKey . ':lkg', []);
                        $headersFromLastKnownGood = !blank($headers);
                        $headersFromCache = $headersFromLastKnownGood;
                    }

                    $fallbackLabel = $headersFromLastKnownGood
                        ? ' — dùng HEADER TỐT GẦN NHẤT (LKG), dòng này có thể lệch cột nếu khách vừa đổi cột'
                        : ($headersFromCache
                            ? ' — sắp cột theo header CŨ trong cache, dòng này có thể lệch cột'
                            : ' — KHÔNG có cache để đỡ');

                    apps_log($logger)->warning('[GSheet] Đọc header tươi THẤT BẠI' . $fallbackLabel, [
                        'spreadsheet_id' => $spreadsheet['spreadsheet']['id'] ?? '',
                        'sheet_id' => $spreadsheet['sheet']['id'] ?? '',
                        'cache_key' => $cacheKey,
                        'fell_back_to_cache' => $headersFromCache,
                        'fell_back_to_lkg' => $headersFromLastKnownGood,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Chỉ lưu khi ĐỌC ĐƯỢC thật. Ghi mảng rỗng vào cache là tự đặt bẫy cho lượt sau.
                if ($cache_headers && !$headersFromCache && !blank($headers)) {
                    Cache::put($cacheKey, $headers, $cache_ttl);
                    Cache::put($cacheKey . ':lkg', $headers, now()->addDays(7));
                }
                #endregion

                #region mapping sample data
                // ' // sample data
                // "Phone": [
                //     {"id": "114974625017517","key": "1367111817480640|số_điện_thoại","label": "Phone number","form_id": "1367111817480640"},
                //     {"id": "1353924855537837","key": "1009385287145182|phone_number","label": "Phone number","form_id": "1009385287145182"}]';
                #endregion

                if (count($mappings)) { // Custom headers mappings w/ "field key" of ads-form vs "column" name of spreadsheet.
                    apps_log($logger)->info('hub_step fields mapping to spreadsheet', (array) $mappings);

                    #region before improve
                    // foreach ($mappings as $mapping_key => $_mappings) {
                    //     $values_mappings[$mapping_key] = null;
                    //     apps_log($logger)->info('mapping_key', (array) $mapping_key);
                    //     if (count($_mappings)) {
                    //         foreach ($_mappings as $__mapping) {
                    //             /** 
                    //              * chú ý: tên cột của spreadsheet không chấp nhận dấu _ và bất kỳ ký tự ngoài các chữ alphebet
                    //              * eg: "1009385287145182|số_điện_thoại" or "số_điện_thoại or sđt_của_cha_mẹ:"
                    //              */
                    //             $__mapping_key = Str::slug($__mapping['key'], '');
                    //             $__mapping_key = isset(explode("|", $__mapping_key)[1]) ? explode("|", $__mapping_key)[1] : $__mapping_key;
                    //             apps_log($logger)->info('__mapping_key slug', (array) $__mapping_key);

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

                    apps_log($logger)->info('hub_step fields mapping data to spreadsheet', $values_mappings);
                }

                foreach ($headers as $header) { // Default headers mappings
                    if ($svalue = Arr::get($spreadsheetData, Str::slug($header, ''))) {
                        $values[$header] = is_array($svalue) ? json_encode($svalue) : $svalue;
                    }
                }
                $values = array_filter($values, fn($value) => !is_null($value) && $value !== '');
                apps_log($logger)->info('Spreadsheet Data with default headers', $values);

                $values = array_merge($values, $values_mappings);
            } else {
                $values = array_values($spreadsheetData);
            }

            apps_log($logger)->info('Spreadsheet values Data', $values);
            // apps_log($logger)->info('Writing to spreadsheet', [
            //     'spreadsheet_id' => $spreadsheet['spreadsheet']['id'],
            //     'sheet_id' => $spreadsheet['sheet']['id'],
            //     'sheet_name' => $spreadsheet['sheet']['name'],
            // ]);

            // apps_log($logger)->info('[GSheet Append::Started]', [
            //     'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
            //     'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),
            //     'values' => $values,
            // ]);

            // 🔴🔴 ĐỪNG "SỬA" CÁCH GHI — vẫn `append()` trần của gói (OVERWRITE), KHÔNG tự tính dòng cuối,
            // KHÔNG `values.update` vào A{last+1}, KHÔNG INSERT_ROWS. Các cách đó đã thử trên v7.x và bị
            // gỡ vì gây sự cố nặng hơn (mất lead im lặng do ghi đè lẫn nhau). Muốn đụng: HỎI CHỦ SẢN PHẨM.
            //
            // [Port từ v7.x — commit 13058ea] SẮP SẴN THEO HEADER TRƯỚC KHI GỌI `append()`.
            // `append()` gọi `orderAppendables()`; với mảng CÓ KHOÁ, hàm đó tự đọc header bằng
            // `first()` → `all()` ⇒ tải TOÀN BỘ tab MỖI LEAD (tab 26.783 dòng ≈ 24 MB/lượt ghi).
            // Truyền mảng đã tuần tự thì `orderAppendables()` trả về ngay, không đọc gì.
            //
            // Công thức sao đúng `SheetsValues::orderAppendables()` của gói BẢN CŨ (5.x/6.x) mà nền v6
            // dùng: ô trống khi `is_null($value) || $key === $value`. KHÔNG dùng `empty()` như bản
            // v7.x (gói 7.x đã đổi sang `empty()`): với gói cũ, ô có giá trị "0" vẫn được ghi là "0".
            //
            // Nhánh `$with_keys = false` đã `array_values()` từ trước ⇒ `isAssoc` false ⇒ bỏ qua.
            if (!blank($headers) && !blank($values) && Arr::isAssoc($values)) {
                $ordered = array_values(array_replace(array_flip($headers), $values));

                $values = [];
                foreach ($ordered as $columnIndex => $cellValue) {
                    $values[] = (is_null($cellValue) || $columnIndex === $cellValue) ? '' : $cellValue;
                }
            }

            // [Port từ v7.x — commit a7f8191] CHẶN LƯỢT GHI CHẮC CHẮN HỎNG — thà báo lỗi còn hơn mất
            // lead im lặng. `$headers` rỗng (đọc tươi hỏng, cache hỏng, LKG cũng rỗng) ⇒ `$values` còn
            // dạng CÓ KHOÁ ⇒ `append()` tự tải TOÀN BỘ tab ⇒ timeout ⇒ dòng không bao giờ được ghi mà
            // không log nào báo. Ném lỗi ở đây không làm mất thêm gì, nhưng biến "mất im lặng" thành
            // "lỗi nhìn thấy được" để nơi gọi ghi nhận và thử lại.
            if (blank($headers) && !blank($values) && Arr::isAssoc($values)) {
                apps_log($logger)->error('[GSheet] BỎ QUA lượt ghi: không có header để sắp cột — lượt này chắc chắn timeout', [
                    'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
                    'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),
                ]);

                throw new Exception(
                    'Không đọc được tiêu đề cột của Google Sheet (Google phản hồi chậm). '
                    . 'Dòng này CHƯA được ghi — hệ thống sẽ thử lại ở lượt đồng bộ sau.'
                );
            }

            // [Port từ v7.x — commit eccf4b9] NEO TÌM BẢNG VÀO CỘT A — chữa lỗi "cột nhảy".
            // `append()` với range = tên tab để Google TỰ DÒ bảng. Khi có dòng trống hoàn toàn nằm giữa
            // dữ liệu (xoá NỘI DUNG dòng thay vì xoá dòng) mà vài cột bên trái trống sẵn, Google coi phần
            // bên phải là "bảng" và ghi lệch sang phải, dòng sau lệch tiếp ⇒ lệch vô tận. Range
            // `'Tab'!A:A` khiến Google chỉ dò trong cột A ⇒ luôn bắt đầu ở A. Chỉ neo khi A1 có tiêu đề.
            //
            // nên chỉ định ->spreadsheet 'spreadsheet.id' tường minh, tránh reuse từ previous stage,
            // cẩn thận việc insert sai spreadsheet nếu context stage "spreadsheet.id" bị tác động ở đâu đó
            $sheetsClient = Sheets::setAccessToken($accessToken)
                ->spreadsheet(Arr::get($spreadsheet, 'spreadsheet.id'))
                ->sheetById(Arr::get($spreadsheet, 'sheet.id'))
                ->range(''); // range "dính" giữa các lượt dùng chung client: luôn dọn trước khi dùng

            $tabTitle = (string) $sheetsClient->ranges();

            try {
                if ($tabTitle !== '' && !blank(Arr::get($headers, 0))) {
                    $sheetsClient->range(apps_gsheet_append_range($tabTitle));
                }

                $result = $sheetsClient->append([$values]);
            } finally {
                $sheetsClient->range('');
            }

            $writtenRange = (string) data_get($result->toSimpleObject(), 'updates.updatedRange', '');
            if ($writtenRange !== '' && !blank(Arr::get($headers, 0)) && !apps_gsheet_range_starts_at_column_a($writtenRange)) {
                apps_log($logger)->error('[GSheet] Dòng vừa ghi KHÔNG bắt đầu ở cột A — nghi lệch cột', [
                    'spreadsheet_id' => Arr::get($spreadsheet, 'spreadsheet.id'),
                    'sheet_id' => Arr::get($spreadsheet, 'sheet.id'),
                    'updated_range' => $writtenRange,
                ]);
            }

            // apps_log($logger)->info('[GSheet Append::Done]', [
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
            apps_log($logger)->error(__FUNCTION__, (array) $th->getMessage());
            apps_log($logger)->error(__FUNCTION__, (array) $th->getTraceAsString());
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

        apps_log($logger)->warning(__FUNCTION__ . '::Unexpected flow fallback');
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
 * {"error":false,"code":200,"statusCode":200,"data":{"workbookId":"1fdSNJLGjJDYq74pw_n4GCiNIYiM7DPf51eeDZRPwY0I","sheetId":0,"sheetName":"Raw","spreadsheetId":"1fdSNJLGjJDYq74pw_n4GCiNIYiM7DPf51eeDZRPwY0I","tableRange":"Raw!A1:J2557","updates":{"spreadsheetId":"1fdSNJLGjJDYq74pw_n4GCiNIYiM7DPf51eeDZRPwY0I","updatedCells":10,"updatedColumns":10,"updatedRange":"Raw!A2558:J2558","updatedRows":1}},"message":"Request has been successfully processed","provider":"leadgen_notification_spreadsheet"}
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

        // xác định dòng cần update
        preg_match('/!([A-Z]+)(\d+):/', $updatedRange, $matches);
        $startCol = $matches[1] ?? 'A';
        $rowIndex = $matches[2] ?? null;

        if (!$rowIndex) {
            return [
                'error' => true,
                'message' => 'Could not extract row index from updatedRange',
            ];
        }

        // tính số cột (A, B, C...) → số lượng phần tử của $rowData
        $endColIndex = count($rowData) - 1;
        $endCol = apps_num2alpha($endColIndex); // ví dụ: 0 → A, 9 → J

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
     * Tạo logger channel động theo tên + optional author_id
     *
     * @param string $logger
     * @param int|null $author_id
     * @return string
     */
    function apps_log_channel(string $logger, $author_id = null): string
    {
        try {
            $loggerName = ($author_id ? "member-{$author_id}-" : '') . $logger;

            // Nếu channel chưa tồn tại thì cấu hình nó
            if (!Config::get("logging.channels.{$loggerName}")) {
                Config::set("logging.channels.{$loggerName}", [
                    'driver' => 'daily',
                    'path'   => storage_path("logs/{$loggerName}.log"),
                    'level'  => env('APP_LOG_LEVEL', 'debug'),
                    'days'   => 14,
                    'lazy'   => true, // tối ưu I/O ghi disk, không nên bật nếu ghi log quan trọng liên quan tới log tài chính vì cần phải ghi ngay.
                ]);
            }

            return $loggerName;
        } catch (\Throwable $th) {
            Log::error('apps_log_channel exception', [
                'message' => $th->getMessage(),
                'trace'   => $th->getTraceAsString(),
            ]);
            return config('logging.default');
        }
    }
}

if (!function_exists('apps_log_write')) {
    /**
     * Ghi log nâng cao cho ứng dụng.
     *
     * Hỗ trợ log thường + log block có cấu trúc:
     *   start → step → success/fail
     *
     * Example 1: Log thường (giống version cũ)
     * ----------------------------------------------------
     * apps_log_write('info', 'Something happened', 'facebook-webhook');
     *
     * Example 2: Log block với START / SUCCESS / FAIL
     * ----------------------------------------------------
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'start');
     * apps_log_write('info', 'Đang phân tích dữ liệu', 'facebook-webhook', 'step');
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'success');
     *
     * Example 3: Log block bị lỗi
     * ----------------------------------------------------
     * apps_log_write('info', 'Webhook@handle', 'facebook-webhook', 'start');
     * try {
     *     throw new \Exception('Lỗi parse JSON');
     * } catch (\Throwable $e) {
     *     apps_log_write('error', 'Webhook@handle', 'facebook-webhook', 'fail', $e);
     * }
     *
     * @param string $level       Mức log: info|debug|warning|error
     * @param string $message     Nội dung hoặc tên block (nếu block log)
     * @param string|null $channel Kênh log, mặc định theo config logging.default
     * @param string $mode        normal | start | success | fail | step
     * @param \Throwable|null $exception Ngoại lệ nếu có (fail mode)
     *
     * @return void
     */
    function apps_log_write(
        string $level,
        string $message,
        $channel = null,
        string $mode = 'normal',
        $exception = null
    ) {
        static $blockStartTimes = [];

        $channelName = $channel ? apps_log_channel($channel) : config('logging.default');

        // Khi block bắt đầu → lưu timestamp
        if ($mode === 'start') {
            $blockStartTimes[$message] = microtime(true);
            Log::channel($channelName)->{$level}("📥 [BLOCK START] {$message}");
            return;
        }

        // Khi block thành công → tính duration
        if ($mode === 'success') {
            $duration = isset($blockStartTimes[$message])
                ? round((microtime(true) - $blockStartTimes[$message]) * 1000, 2)
                : 0;

            unset($blockStartTimes[$message]);
            Log::channel($channelName)->{$level}("✅ [BLOCK END] {$message} (duration={$duration}ms)");
            return;
        }

        // Khi block thất bại → log fail + duration + exception
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

        // Log từng bước trong block → không reset timestamp
        if ($mode === 'step') {
            $elapsed = isset($blockStartTimes[$message])
                ? round((microtime(true) - $blockStartTimes[$message]) * 1000, 2)
                : 0;

            Log::channel($channelName)->{$level}("🔹 [STEP +{$elapsed}ms] {$message}");
            return;
        }

        // Mặc định → log thông thường như phiên bản cũ
        Log::channel($channelName)->{$level}($message);
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

if (!function_exists('apps_log_stringify')) {
    /**
     * Chuyển giá trị bất kỳ thành chuỗi an toàn để log
     *
     * @param mixed $value
     * @return string
     */
    function apps_log_stringify($value)
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
            } catch (\Throwable $e) {
                return 'Object(' . get_class($value) . ')';
            }
        }

        return '[Unloggable type]';
    }
}

if (!function_exists('apps_pull_login')) {

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
            apps_log($logger)->error('Exception', $result);
        }

        return $result;
    }
}

if (!function_exists('apps_vtiger_login')) {

    function apps_vtiger_login($username, $accessKey, $method = 'POST', $logger = 'daily')
    {
        // apps_log($logger)->info("==========> " . __FUNCTION__ . " helper is running");
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

                // apps_log($logger)->info($challenge);

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
                    // apps_log($logger)->info($result);

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

    function apps_toyota_crm_login($username, $password, $method = 'POST', $logger = 'daily')
    {
        try {
            // apps_log($logger)->info("==========> " . __FUNCTION__ . " helper is running");
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

    function apps_mmv_crm_login($username = 'mmv_tsp', $password = 'Ci8p2P3B', $method = 'POST', $logger = 'daily'): array
    {
        try {
            // apps_log($logger)->info("==========> " . __FUNCTION__ . " helper is running");
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
     *
     * @param
     *            $path
     * @param array $ignore_files
     * @return array
     * @author Anonymous Developer Department
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

/**
 * Check if a given string is a valid UUID
 *
 * @param   string  $uuid   The string to check
 * @return  boolean
 */
if (!function_exists('apps_check_valid_uuid')) {
    function apps_check_valid_uuid($uuid)
    {
        if (!is_string($uuid) || (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('apps_apollo_crm_login')) {

    function apps_apollo_crm_login($username, $password, $sogoAccessToken, $method = 'POST', $logger = 'daily')
    {
        try {
            // apps_log($logger)->info("==========> " . __FUNCTION__ . " helper is running");
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
/**
 * Convert JSON data to array, adding new value before store to database
 *
 * @param   array|string|null $original JSON | ARRAY
 * @param   mixed  $value
 * @param   int|string|null  $key
 * @return  array , sử dụng casts trong model để xử lý json data, tránh sử dụng json_decode/json_encode
 */
if (!function_exists('apps_json_to_database')) {
    function apps_json_to_database($original, $value, $key = null, $override = true)
    {
        if (is_string($original)) { // '{}'
            try {
                $original = json_decode(blank($original) ? '{}' : $original, true, 512, JSON_THROW_ON_ERROR); // hoặc nếu chắc chắn json ổn định: $original = json_decode(blank($original) ? '{}' : $original, true);
            } catch (\JsonException $e) {
                Log::error('[apps_json_to_database] JSON decode failed', ['input' => $original, 'error' => $e->getMessage()]);
                $original = []; // fallback an toàn
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

/**
 * Usage: apps_province_detection('(Thanh Hoá) ĐL Thanh Hoá_TTH')
 * Return "Thanh Hoá"
 */
if (!function_exists('apps_province_detection')) {
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
/**
 * Usage: apps_get_provinces_districts_rawdata('Thanh Hoá')
 * Return all provinces with its districts or just return districts belong to a specific province
 */
if (!function_exists('apps_get_provinces_districts_rawdata')) {
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
            // apps_log($logger)->info('Bad Signed JSON signature!');
            error_log('Bad Signed JSON signature!');
            return null;
        }

        return $data;
    }
}
if (!function_exists('apps_facebook_base64_url_decode')) {
    function apps_facebook_base64_url_decode($input)
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }
}

/**
 * Send a message via Telegram w/ default configs
 *
 */
if (!function_exists('apps_telegram_send_message')) {
    function apps_telegram_send_message(
        $message,
        $channel = 'pull',
        $logger = 'daily',
        $configs = []
    ) {
        #region Important Telegram notification
        try {
            if (blank($logger)) $logger = 'daily';

            if (!in_array(app()->environment(), ['production', 'prod'], true)) { // giới hạn gửi ở production
                apps_log($logger)->info('[Telegram::Skipped]', [
                    'env' => app()->environment()
                ]);
                return;
            }

            $telegramDfOptions = [
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'link_preview_options' => ['is_disabled' => true],
                'chat_id' => config("telegram.bots.{$channel}.chat_id", '-1001541977083')
            ];

            if (!blank($configs)) {
                $telegramDfOptions = [
                    ...$telegramDfOptions,
                    ...$configs // high priority & override
                ];
            }

            // apps_log($logger)->info("configs", $configs);
            // apps_log($logger)->info("telegramDfOptions", $telegramDfOptions);

            $telegramDfOptions['text'] = \Illuminate\Support\Str::limit( // có thể sử dụng facades app('url) nhưng không sử dụng được helper str() lúc này, có thể vì chưa load
                is_array($message) ?
                    implode("\n", $message) : $message,
                4096
            );

            $telegram = new \Telegram\Bot\Api(); // getenv TELEGRAM_BOT_TOKEN, it's working
            $telegram->sendMessage($telegramDfOptions);
        } catch (Throwable $th) {
            apps_log($logger)->error(__FUNCTION__, (array) $th->getMessage());
            // DO NOT THROW
        }
        #endregion
    }
}

if (!function_exists('apps_phone_extraction')) {
    function apps_phone_extraction($str, $pattern = '/(\+\d{1,2})?\s?\(?\d{1,4}\)?[\s.-]?\d{3}[\s.-]?\d{4}/')
    {
        $str = Str::slug($str, '');
        preg_match_all($pattern, $str, $matches); // custom pattern

        return trim(Arr::get($matches, '0.0', null));
    }
}

#region bộ hàm apps_cache_*
/**
 * Tạo ra cache key và ghi nhớ nó thuộc group nào.
 * Nếu $group được truyền vào:
 * Ghi nhận key này trong danh sách group_xxx (kiểu mảng lưu tối đa 100 key).
 * Ghi nhận tên group vào danh sách toàn cục (dùng cho reset sau này).
 * Nếu không có $group, chỉ đơn giản trả về $cacheKey.
 * 
 * @param string $cacheKey: tên cache cụ thể (ví dụ: user_1234)
 * @param string $group: tên nhóm cache (ví dụ: user_list)
 * 
 * Trả lại key dùng để lưu vào cache.
 */
if (! function_exists('apps_cache_get_key')) {
    /**
     * Tự động tạo key trong danh sách group
     * Lưu lại group vào danh sách các key để quản lí và xoá
     * Tối đa chỉ lưu 200 key cho mỗi group để tránh quá tải redis hoặc Mem máy.
     * Có thể chuyển qua dạng stack để có thể duy trì được cache sống lâu hơn nếu đặt giới hạn
     * @param string $cacheKey : Key cache trong group, nếu không có thì put queue vào trong danh sách key của group
     * @param string|null : Tên group cache, chứa các key chung nhóm. nếu đặt null thì là một cacheKey riêng không nằm trong group nào cả
     */
    function apps_cache_get_key(string $cacheKey = 'default',  $group = null)
    {
        try {
            // Prefix key theo APP_NAME để phân tách giữa các app/DB
            $prefix = Str::slug(env('APP_NAME', 'app'));
            $appliedKey = $prefix . ":" . $cacheKey;

            // nếu không có group, trả về key kèm prefix app name
            if (blank($group)) {
                return $appliedKey;
            }

            /**
             * Add to data cache list of app
             */
            $app_cache_key = md5("app_data_cache_list");
            $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : [];
            $groupSlug = Str::slug($group);
            $cache_list[$groupSlug] = true; // Lưu tên group (slug) vào danh sách quản lí app cache
            Cache::forever($app_cache_key, $cache_list);

            /* lấy danh sách key của group, sử dụng slug để tránh ký tự đặc biệt */
            $groupName = "group_" . $groupSlug;
            $groupCacheKey = md5($groupName);
            $groupData = json_decode(Cache::get($groupCacheKey, '[]'), true) ?: [];

            /* sử dụng stack để lưu dữ liệu, giới hạn tối đa 100 key */
            $MAX_ITEM = 100;
            if (!in_array($appliedKey, $groupData)) {
                if (count($groupData) >= $MAX_ITEM) {
                    Cache::forget($groupData[0]); // Xóa phần tử đầu tiên nếu đạt giới hạn
                    array_shift($groupData);
                }
                array_push($groupData, $appliedKey); // Thêm phần tử mới vào cuối mảng
                Cache::forever($groupCacheKey, apps_json_encode($groupData));
            }

            /* trả về key kèm prefix app name */
            return $appliedKey;
        } catch (\Throwable $th) {
            /* log lỗi nếu có exception */
            Log::channel(apps_log_channel("app_cache"))->error("Get data error at: " . $cacheKey . ", " . $group);
            Log::channel(apps_log_channel("app_cache"))->error($th->getMessage());
            return Str::slug(env('APP_NAME')) . ":" . 'default';
        }
    }
}

if (! function_exists('apps_cache_store')) {
    /**
     * Lưu dữ liệu vào cache với khả năng nhóm theo group để dễ dàng quản lý và xóa theo nhóm.
     *
     * @param string $key    Khóa cache duy nhất để lưu trữ dữ liệu.
     * @param mixed  $data   Dữ liệu cần lưu vào cache.
     * @param int    $time   Thời gian hết hạn của cache (tính bằng giây, mặc định 1 giờ).
     * @param string|null $group Tên nhóm cache để hỗ trợ xóa theo nhóm (tuỳ chọn).
     *
     * 
     * apps_cache_store('user_123', $userData, 3600, 'users');
     * 
     * @return void
     */
    function apps_cache_store(
        string $key = 'default',
        $data = '',
        $time = 60 * 60,
         $group = null
    ) {
        try {
            $cacheKey = apps_cache_get_key($key, $group);
            Cache::put($cacheKey, $data, $time);
        } catch (\Throwable $th) {
            Log::channel(apps_log_channel("app_cache"))->error("Store data error at: " . $key . ", " . $group);
            Log::channel(apps_log_channel("app_cache"))->error($th->getMessage());
        }
    }
}

if (! function_exists('apps_cache_get')) {
    /**
     * Lấy dữ liệu từ cache theo cùng logic tạo key (có hỗ trợ group/prefix).
     * Dùng hàm này thay vì gọi trực tiếp cache()->get($key) để tránh sai key
     * khi thay đổi cơ chế prefix (ví dụ bật prefix theo APP_NAME).
     *
     * @param string   $key
     * @param mixed    $default
     * @param ?string  $group
     * @return mixed
     */
    function apps_cache_get(string $key = 'default', $default = null, $group = null)
    {
        try {
            $cacheKey = apps_cache_get_key($key, $group);
            return Cache::get($cacheKey, $default);
        } catch (\Throwable $th) {
            Log::channel(apps_log_channel("app_cache"))->error("Get data error at: " . $key . ", " . $group);
            Log::channel(apps_log_channel("app_cache"))->error($th->getMessage());
            return $default;
        }
    }
}

if (! function_exists('apps_cache_flush')) {
    /**
     * Xóa dữ liệu trong cache theo key hoặc theo nhóm.
     *
     * - Nếu chỉ truyền `$cacheKey`, hàm sẽ xóa cache theo key cụ thể.
     * - Nếu truyền `$group`, hàm sẽ xóa tất cả cache thuộc nhóm đó và xóa luôn nhóm cache.
     *
     * @param string|null $cacheKey  Khóa cache cần xóa (mặc định là 'default').
     * @param string|null $group     Nhóm cache cần xóa toàn bộ (tuỳ chọn).
     *
     * apps_cache_flush('user_123'); // xoá 1 key
     * apps_cache_flush(null, 'users'); // xoá cả nhóm 'users
     * 
     * @return void
     */
    function apps_cache_flush(
         $cacheKey = 'default',
         $group = null
    ) {
        try {
            if (blank($group)) {
                // Tính key đã áp dụng prefix để xoá chính xác
                $appliedKey = apps_cache_get_key($cacheKey, null);
                Cache::forget($appliedKey); // Nếu không truyền group thì là xoá một key
                Log::channel(apps_log_channel("app_cache"))->debug("Flushed cached data $cacheKey");
            } else {
                // Xoá toàn bộ key trong group đó.
                $groupSlug = Str::slug($group);
                $groupCacheKey = md5("group_" . $groupSlug);
                $groupData = [];
                if (Cache::has($groupCacheKey)) {
                    $groupData = json_decode(Cache::get($groupCacheKey), true) ?? [];
                    if (!blank($groupData) && count($groupData) > 0) {
                        foreach ($groupData as $key => $value) {
                            Cache::forget($value); // Xoá cache con trong group
                            Log::channel(apps_log_channel("app_cache"))->debug("Flushed cached data $value");
                        }
                        Log::channel(apps_log_channel("app_cache"))->debug("Flushed cached data $groupCacheKey");
                        Cache::forget($groupCacheKey); // Xoá luôn tệp group sau khi xoá hết các cacheKey con nằm trong nó
                    }
                }
            }
        } catch (\Throwable $th) {
            Log::channel(apps_log_channel("app_cache"))->error("Flush data error at: $cacheKey, $group");
            Log::channel(apps_log_channel("app_cache"))->error($th->getMessage());
        }
    }
}

if (! function_exists('apps_cache_reset')) {
    /**
     * Đặt lại toàn bộ cache của ứng dụng bằng cách xóa tất cả các nhóm cache đã lưu.
     *
     * - Lấy danh sách các nhóm cache đã lưu.
     * - Duyệt qua từng nhóm và gọi `apps_cache_flush()` để xóa toàn bộ cache trong nhóm.
     * - Xóa luôn danh sách quản lý cache sau khi hoàn tất.
     * - Ghi log quá trình xóa để theo dõi.
     *
     * @return void
     */
    function apps_cache_reset()
    {
        try {
            $app_cache_key = md5("app_data_cache_list");
            $cache_list = Cache::has($app_cache_key) ? Cache::get($app_cache_key) : []; // Lấy danh sách group
            foreach ($cache_list as $key => $flag) {
                Log::channel(apps_log_channel("app_cache"))->info("- Delete cache group " . $key);
                apps_cache_flush(null, $key); // Lọc từng group và xoá hết các cachekey trong nó
            }
            Cache::forget($app_cache_key); // Xoá luôn tệp quản lí cache
        } catch (\Throwable $th) {
            Log::channel(apps_log_channel("app_cache"))->error("Reset cache data error");
            Log::channel(apps_log_channel("app_cache"))->error($th->getMessage());
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
        $appliedKey = apps_cache_get_key($key, null);
        $status = Cache::has($appliedKey) ? 'HIT' : 'MISS';

        $logData = [
            'cache_key' => $key,
            'applied_cache_key' => $appliedKey,
            'status' => $status,
        ];

        if ($showValue && $status === 'HIT') {
            $logData['value'] = apps_cache_get($key, null, null);
        }

        Log::channel($channel)->info('[Cache Debug]', $logData);
    }
}
#endregion

if (!function_exists('apps_is_valid_timestamp')) {
    function apps_is_valid_timestamp($timestamp)
    {
        return ((string) (int) $timestamp === $timestamp)
            && ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= ~PHP_INT_MAX);
    }
}

/**
 *{
 *    "name": "Hành Trình Tôi Chọn",
 *    "url": "https://hanhtrinhtoichon.com/mimi-chatbot/",
 *    "status": "Hoạt động tốt"
 *}
 * 
 * @param bool pretty = true sẽ return mã json nhiều dòng, ngược lại trả ra mã json trên 1 dòng
 * @param bool withEol = true sẽ thêm ký tự xuống hàng
 */
if (!function_exists('apps_json_encode')) {
    /**
     * @param array|object|null $data
     * @param bool $pretty
     * @param bool $withEol
     * @param bool $throwOnError
     * @return string
     */
    function apps_json_encode(
        $data,
        bool $pretty = false,
        bool $withEol = false,
        bool $throwOnError = true // mặc định là true, trừ khi có lý do rõ ràng để không dùng
    ) {
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
     * Lấy giá trị của key đầu tiên có giá trị không rỗng trong mảng.
     * @param array $array Mảng nguồn
     * @param array $keys Danh sách key theo thứ tự ưu tiên
     * @param mixed $default Giá trị trả về nếu không có key nào hợp lệ
     * @return mixed
     */
    function apps_array_get_first_non_empty(array $array, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            $value = Arr::get($array, $key);
            if (!is_null($value) && trim((string)$value) !== '') {
                return $value;
            }
        }
        return $default;
    }
}

/**
 * sử dụng hàm này trước khi gọi helper Arr::get để tránh lỗi khi gặp key tồn tại nhưng value null.
 * To remove the null values but not the empty arrays
 */
if (!function_exists('apps_array_remove_null')) {
    function apps_array_remove_null($item, bool $resetNumericKeys = true)
    {
        if (!is_array($item)) return $item;

        $filtered = [];

        foreach ($item as $key => $value) {
            $value = is_array($value)
                ? apps_array_remove_null($value, $resetNumericKeys)
                : $value;

            if ($value === null || $value === '' || $value === [] || $value === "") {
                continue;
            }

            $filtered[$key] = $value; // luôn giữ $key => $value
        }

        return $resetNumericKeys && array_is_list($filtered)
            ? array_values($filtered)
            : $filtered; // reset lại key nếu mảng số và flag được bật
    }
}

if (!function_exists('apps_extract_additional_data')) {
    /**
     * Chuẩn hóa dữ liệu bổ sung (additional_data) trước khi lưu vào DB.
     * - Chỉ giữ lại các field không có trong schema của bảng.
     * - Hỗ trợ cache schema để giảm query.
     *
     * @param  array   $data    Dữ liệu nguồn (tham chiếu & cập nhật trực tiếp).
     * @param  string  $schema  Tên bảng (mặc định: leads).
     * @param  array|null   $additionalDataIgnore Các field không cần lưu vào additional_data
     * @return array   Mảng dữ liệu bổ sung (đã chuẩn hóa).
     */
    function apps_extract_additional_data(
        array $data,
        string $schema = "leads",
        $additionalDataIgnore = null // các field không cần lưu vào additional_data
    ) {
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
        ]; // gán data mặc định nếu không truyền

        // lấy danh sách cột từ cache hoặc DB
        $cacheKey = "schema_$schema";
        $cols = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($schema) {
            return DB::connection()->getSchemaBuilder()->getColumnListing($schema);
        });

        $additionalData = [];
        // phân tích và thu thập dữ liệu bổ sung, có 3 cách xử lý: array_walk, foreach hoặc laravel (Collection + reject)
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
    function apps_as_array($value): array
    {
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('apps_get_image_url_webp')) {
    /**
     * Trả về URL phiên bản WebP của ảnh nếu file .webp tương ứng tồn tại trên máy chủ.
     *
     * Cách hoạt động:
     * - Chỉ áp dụng cho ảnh có phần mở rộng: jpg|jpeg|png.
     * - Phân tích URL để lấy path (bỏ query/fragment), tạo path .webp tương ứng với ảnh gốc.
     * - Kiểm tra sự tồn tại của file .webp trong thư mục public (public_path).
     * - Nếu tồn tại: trả về URL .webp nhưng giữ nguyên query/fragment gốc (nếu có).
     * - Nếu không tồn tại: trả về URL ảnh gốc.
     *
     * Tối ưu hiệu năng:
     * - Dùng memoization theo originalUrl để tránh lặp lại thao tác I/O (File::exists) trong cùng lifecycle request.
     *
     * Lưu ý:
     * - Hàm không chuyển đổi ảnh sang WebP, chỉ kiểm tra sự tồn tại file .webp sẵn có.
     * - Nếu URL rỗng hoặc không có path hợp lệ → trả null/URL gốc tương ứng.
     * - Hành vi phụ thuộc mapping giữa URL và public_path. Cần cấu hình đồng nhất URL tĩnh ↔ thư mục public.
     *
     * @param string $originalUrl URL ảnh gốc (có thể kèm query/fragment)
     * @return string|null URL .webp nếu có, ngược lại URL gốc; null khi tham số rỗng
     */
    function apps_get_image_url_webp($originalUrl)
    {
        static $memo = [];

        if (empty($originalUrl)) {
            return null;
        }

        if (isset($memo[$originalUrl])) {
            return $memo[$originalUrl];
        }

        // Lấy path an toàn từ URL (bỏ query/fragment), tránh File::extension trên full URL
        $path = (string) (parse_url($originalUrl, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return $memo[$originalUrl] = $originalUrl;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return $memo[$originalUrl] = $originalUrl;
        }

        // Tạo đường dẫn .webp tương ứng theo path, giữ nguyên domain/query của URL gốc khi trả về
        $webpPath = substr($path, 0, -strlen($ext)) . 'webp';
        $filePath = public_path(ltrim($webpPath, '/'));

        if (!File::exists($filePath)) {
            return $memo[$originalUrl] = $originalUrl;
        }

        // Thay phần mở rộng ở URL gốc (chỉ ở cuối), giữ nguyên query/fragment
        $webpUrl = preg_replace('/\.' . preg_quote($ext, '/') . '(\?.*)?$/i', '.webp$1', $originalUrl);

        return $memo[$originalUrl] = ($webpUrl ?: $originalUrl);
    }
}
