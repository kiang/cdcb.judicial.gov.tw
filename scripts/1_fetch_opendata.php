<?php

if (! function_exists("array_key_last")) {
    function array_key_last($array) {
        if (!is_array($array) || empty($array)) {
            return NULL;
        }
       
        return array_keys($array)[count($array)-1];
    }
}

$rootPath = dirname(__DIR__);
$fileToFix = $rootPath . '/toFix.csv';
$fixFh = fopen($fileToFix, 'w');
include_once $rootPath . '/lib/cns11643/scripts/big5e_to_utf8.php';
$rawPath = "{$rootPath}/raw";
if(!file_exists($rawPath)) {
    mkdir($rawPath, 0777, true);
}
$outputPath = "{$rootPath}/json";
$tmpPath = "{$rootPath}/tmp";

$all_court = array(
    'TPD&臺灣台北地方法院',
    'PCD&臺灣新北地方法院',
    'SLD&臺灣士林地方法院',
    'TYD&臺灣桃園地方法院',
    'SCD&臺灣新竹地方法院',
    'MLD&臺灣苗栗地方法院',
    'TCD&臺灣臺中地方法院',
    'NTD&臺灣南投地方法院',
    'CHD&臺灣彰化地方法院',
    'ULD&臺灣雲林地方法院',
    'CYD&臺灣嘉義地方法院',
    'TND&臺灣臺南地方法院',
    'KSD&臺灣高雄地方法院',
    'PTD&臺灣屏東地方法院',
    'TTD&臺灣臺東地方法院',
    'HLD&臺灣花蓮地方法院',
    'ILD&臺灣宜蘭地方法院',
    'KLD&臺灣基隆地方法院',
    'PHD&臺灣澎湖地方法院',
    'KSY&臺灣高雄少年法院',
    'LCD&褔建連江地方法院',
    'KMD&福建金門地方法院',
);

foreach($all_court AS $court) {
    $courtParts = explode('&', $court);
    $csvFile = $rawPath . '/' . $courtParts[0] . '.csv';
    if(!file_exists($csvFile) || (time() - filemtime($csvFile)) > 100000) {
        file_put_contents($csvFile, file_get_contents('http://cdcb.judicial.gov.tw/abbs/wkw/WHD6K00_DOWNLOADCVS.jsp?court=' . $courtParts[0]));
    }
    $fh = fopen($csvFile, 'r');
    $header = fgetcsv($fh, 2048);
    foreach($header AS $k => $v) {
        $header[$k] = Converter::iconv($v, 1);
    }
    $headerCount = count($header);
    while($line = fgetcsv($fh, 4096)) {
        foreach($line AS $k => $v) {
            $line[$k] = Converter::iconv($v, 1);
        }
        while($headerCount > count($line)) {
            $nextLine = fgetcsv($fh, 4096);
            foreach($nextLine AS $k => $v) {
                $nextLine[$k] = Converter::iconv($v, 1);
                if($k === 0) {
                    $lastKey = array_key_last($line);
                    $line[$lastKey] .= "\n" . $nextLine[0];
                    end($line);
                } else {
                    if($headerCount > count($line)) {
                        $line[] = $nextLine[$k];
                    }
                }
            }
        }
        if(count($header) !== count($line)) {
            fputcsv($fixFh, $line);
            continue;
        }
        $data = array_combine($header, $line);
        $y = substr($data['設立登記日期'], 0, 3);
        $targetPath = "{$outputPath}/{$courtParts[0]}/{$y}";
        if(!file_exists($targetPath)) {
            mkdir($targetPath, 0777, true);
        }
        $targetFile = "{$targetPath}/{$data['登記案號']}.json";
        if(file_exists($targetFile)) {
            continue;
        }
        preg_match_all('/[0-9]+/', $data['登記案號'], $matches, PREG_OFFSET_CAPTURE);
        $len = strlen($matches[0][0][0]);
        $parts = array(
            $matches[0][0][0],
            urlencode(mb_convert_encoding(substr($data['登記案號'], $len, $matches[0][1][1] - $len), 'big5', 'utf-8')),
            $matches[0][1][0],
        );
        
        $query = 'bc=' . $y . '&cd=' . $parts[1] . '&de=' . $parts[2] . '&ef=' . $courtParts[0] . '&fg=1';
        $dataTmpPath = "{$tmpPath}/{$courtParts[0]}/{$y}";
        if(!file_exists($dataTmpPath)) {
            mkdir($dataTmpPath, 0777, true);
        }
        $data['url'] = array(
            'staff' => 'http://cdcb.judicial.gov.tw/abbs/wkw/WHD6K04.jsp?' . $query,
            'office' => 'http://cdcb.judicial.gov.tw/abbs/wkw/WHD6K07.jsp?' . $query,
        );
        $data['董監事'] = array();
        $data['分事務所'] = array();
        $fileStaff = "{$dataTmpPath}/{$data['登記案號']}_staff.html";
        if(!file_exists($fileStaff)) {
            file_put_contents($fileStaff, file_get_contents($data['url']['staff']));
        }
        $textStaff = mb_convert_encoding(file_get_contents($fileStaff), 'utf-8', 'big5');
        $rows = explode('</tr>', $textStaff);
        foreach($rows AS $row) {
            $cols = explode('</td>', $row);
            if(count($cols) === 4) {
                foreach($cols AS $k => $v) {
                    $cols[$k] = str_replace(' ', '', trim(strip_tags($v)));
                }
                if($cols[0] !== '序號') {
                    $data['董監事'][] = array(
                        $cols[1], $cols[2],
                    );
                }
            }
        }

        $fileOffice = "{$dataTmpPath}/{$data['登記案號']}_office.html";
        if(!file_exists($fileOffice)) {
            file_put_contents($fileOffice, file_get_contents($data['url']['office']));
        }
        $textOffice = mb_convert_encoding(file_get_contents($fileOffice), 'utf-8', 'big5');
        $rows = explode('</tr>', $textOffice);
        foreach($rows AS $row) {
            $cols = explode('</td>', $row);
            foreach($cols AS $k => $v) {
                $cols[$k] = str_replace(' ', '', trim(strip_tags($v)));
            }
        if(count($cols) === 3) {
                if($cols[1] !== '分事務所資料' && $cols[1] !== '分事務所地址') {
                    $data['分事務所'][] = $cols[1];
                }
            }
        }
        file_put_contents($targetFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));
        error_log("done - {$targetFile}");
    }
}