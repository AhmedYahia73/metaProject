<?php

namespace App\trait;

use Illuminate\Support\Facades\File;

trait translaion
{
    public function translate($file_name, $key, $value)
    {

        $filePath = base_path("lang/{$file_name}/messages.php");
        if (! File::exists($filePath)) {
            return response()->json([
                'faild' => 'File does not exist'],
                400);
        }
        $translations = require $filePath;
        $translations[$key] = $value;
        // Save the updated translations back to the file
        $content = "<?php\n\nreturn ".var_export($translations, true).";\n";
        File::put($filePath, $content);
    }
    // public function translate( $en_name, $ar_name ){
    //     // Add to file translation
    //     $filePath = resource_path("lang\\ar.json"); // Get Path
    //     $filePathEn = resource_path("lang\\en.json"); // Get Path
    //     if (file_exists($filePath)) {
    //         $lang = json_decode(file_get_contents($filePath), true);  // Get old data
    //         $lang[$en_name] = $ar_name; // New data
    //         $jsonTranslations = json_encode($lang, JSON_UNESCAPED_UNICODE ); // Convert new data to json
    //         file_put_contents($filePath, $jsonTranslations); // Put data at file json
    //     }
    //     if (file_exists($filePathEn)) {
    //         $lang = json_decode(file_get_contents($filePathEn), true);  // Get old data
    //         $lang[$en_name] = $en_name; // New data
    //         $jsonTranslations = json_encode($lang, JSON_UNESCAPED_UNICODE ); // Convert new data to json
    //         file_put_contents($filePathEn, $jsonTranslations); // Put data at file json
    //     }
    // }

    // public function word_translate( $en_name ){
    //     // Add to file translation
    //     $filePath = base_path("lang\\ar.json"); // Get Path
    //     if (file_exists($filePath)) {
    //         $lang = json_decode(file_get_contents($filePath), true);  // Get file data
    //         return $lang[$en_name]; // return translation
    //     }
    //     return null;
    // }
}
