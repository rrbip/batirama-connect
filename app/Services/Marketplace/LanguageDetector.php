<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Log;

/**
 * Detects product language from various sources.
 *
 * Detection priority:
 * 1. URL path patterns (/fr/, /en/, /de-de/, etc.)
 * 2. URL domain TLD (.fr, .de, .es, etc.)
 * 3. SKU patterns (-FR, -EN, _fr, _en, etc.)
 * 4. Content analysis (common words)
 */
class LanguageDetector
{
    // ─────────────────────────────────────────────────────────────────
    // EUROPE - Langues européennes
    // ─────────────────────────────────────────────────────────────────
    private const EUROPE_LOCALES = [
        // Western Europe
        'fr' => [
            'name' => 'Français',
            'path_patterns' => ['/fr/', '/fr-fr/', '/fra/', '/french/'],
            'tlds' => ['.fr', '.mc'],
            'sku_patterns' => ['-FR', '_FR', '-fr', '_fr', '/FR', '/fr'],
            'common_words' => ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'est', 'pour', 'avec', 'dans', 'sur', 'par'],
        ],
        'en' => [
            'name' => 'English',
            'path_patterns' => ['/en/', '/en-gb/', '/en-us/', '/eng/', '/english/'],
            'tlds' => ['.co.uk', '.uk', '.ie'],
            'sku_patterns' => ['-EN', '_EN', '-en', '_en', '/EN', '/en', '-GB', '-US', '-UK'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have', 'has'],
        ],
        'de' => [
            'name' => 'Deutsch',
            'path_patterns' => ['/de/', '/de-de/', '/de-at/', '/de-ch/', '/deu/', '/german/', '/deutsch/'],
            'tlds' => ['.de', '.at'],
            'sku_patterns' => ['-DE', '_DE', '-de', '_de', '/DE', '/de', '-AT', '-CH'],
            'common_words' => ['der', 'die', 'das', 'und', 'ist', 'mit', 'für', 'auf', 'dem', 'den', 'ein', 'eine', 'nicht'],
        ],
        'es' => [
            'name' => 'Español',
            'path_patterns' => ['/es/', '/es-es/', '/spa/', '/spanish/', '/espanol/'],
            'tlds' => ['.es'],
            'sku_patterns' => ['-ES', '_ES', '-es', '_es', '/ES', '/es'],
            'common_words' => ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'para', 'por', 'una', 'uno', 'que', 'es'],
        ],
        'it' => [
            'name' => 'Italiano',
            'path_patterns' => ['/it/', '/it-it/', '/ita/', '/italian/', '/italiano/'],
            'tlds' => ['.it', '.sm'],
            'sku_patterns' => ['-IT', '_IT', '-it', '_it', '/IT', '/it'],
            'common_words' => ['il', 'la', 'di', 'che', 'per', 'con', 'del', 'della', 'sono', 'una', 'uno', 'non'],
        ],
        'nl' => [
            'name' => 'Nederlands',
            'path_patterns' => ['/nl/', '/nl-nl/', '/nl-be/', '/nld/', '/dutch/', '/nederlands/'],
            'tlds' => ['.nl'],
            'sku_patterns' => ['-NL', '_NL', '-nl', '_nl', '/NL', '/nl'],
            'common_words' => ['de', 'het', 'een', 'van', 'en', 'in', 'is', 'op', 'met', 'voor', 'niet', 'zijn'],
        ],
        'pt' => [
            'name' => 'Português',
            'path_patterns' => ['/pt/', '/pt-pt/', '/pt-br/', '/por/', '/portuguese/'],
            'tlds' => ['.pt'],
            'sku_patterns' => ['-PT', '_PT', '-pt', '_pt', '/PT', '/pt'],
            'common_words' => ['de', 'da', 'do', 'para', 'com', 'uma', 'um', 'que', 'os', 'as', 'no', 'na'],
        ],
        'ca' => [
            'name' => 'Català',
            'path_patterns' => ['/ca/', '/cat/', '/catalan/'],
            'tlds' => ['.cat'],
            'sku_patterns' => ['-CA', '_CA', '-cat', '_cat'],
            'common_words' => ['el', 'la', 'els', 'les', 'de', 'del', 'amb', 'per', 'que', 'un', 'una', 'és'],
        ],
        'eu' => [
            'name' => 'Euskara',
            'path_patterns' => ['/eu/', '/eus/', '/basque/'],
            'tlds' => ['.eus'],
            'sku_patterns' => ['-EU', '_EU', '-eus', '_eus'],
            'common_words' => ['eta', 'da', 'bat', 'ez', 'ere', 'baina', 'hau', 'oso', 'den', 'dira'],
        ],
        'gl' => [
            'name' => 'Galego',
            'path_patterns' => ['/gl/', '/gal/', '/galician/', '/galego/'],
            'tlds' => ['.gal'],
            'sku_patterns' => ['-GL', '_GL', '-gal', '_gal'],
            'common_words' => ['o', 'a', 'os', 'as', 'de', 'do', 'da', 'en', 'que', 'un', 'unha', 'para'],
        ],

        // Nordic
        'sv' => [
            'name' => 'Svenska',
            'path_patterns' => ['/sv/', '/sv-se/', '/swe/', '/swedish/', '/svenska/'],
            'tlds' => ['.se'],
            'sku_patterns' => ['-SV', '_SV', '-sv', '_sv', '-SE', '/SE', '/sv'],
            'common_words' => ['och', 'att', 'det', 'är', 'på', 'för', 'som', 'med', 'en', 'av', 'har', 'den'],
        ],
        'no' => [
            'name' => 'Norsk',
            'path_patterns' => ['/no/', '/nb/', '/nn/', '/nor/', '/norwegian/', '/norsk/'],
            'tlds' => ['.no'],
            'sku_patterns' => ['-NO', '_NO', '-no', '_no', '/NO', '/no'],
            'common_words' => ['og', 'er', 'på', 'for', 'som', 'med', 'av', 'til', 'det', 'har', 'en', 'ikke'],
        ],
        'da' => [
            'name' => 'Dansk',
            'path_patterns' => ['/da/', '/dan/', '/danish/', '/dansk/'],
            'tlds' => ['.dk'],
            'sku_patterns' => ['-DA', '_DA', '-da', '_da', '-DK', '/DK', '/da'],
            'common_words' => ['og', 'er', 'at', 'en', 'det', 'på', 'for', 'af', 'med', 'til', 'som', 'har'],
        ],
        'fi' => [
            'name' => 'Suomi',
            'path_patterns' => ['/fi/', '/fin/', '/finnish/', '/suomi/'],
            'tlds' => ['.fi'],
            'sku_patterns' => ['-FI', '_FI', '-fi', '_fi', '/FI', '/fi'],
            'common_words' => ['ja', 'on', 'ei', 'se', 'että', 'oli', 'myös', 'kun', 'tai', 'niin', 'joka', 'vain'],
        ],
        'is' => [
            'name' => 'Íslenska',
            'path_patterns' => ['/is/', '/isl/', '/icelandic/'],
            'tlds' => ['.is'],
            'sku_patterns' => ['-IS', '_IS', '-is', '_is'],
            'common_words' => ['og', 'að', 'er', 'það', 'um', 'en', 'til', 'með', 'hann', 'við', 'sem', 'var'],
        ],

        // Central & Eastern Europe
        'pl' => [
            'name' => 'Polski',
            'path_patterns' => ['/pl/', '/pl-pl/', '/pol/', '/polish/', '/polski/'],
            'tlds' => ['.pl'],
            'sku_patterns' => ['-PL', '_PL', '-pl', '_pl', '/PL', '/pl'],
            'common_words' => ['i', 'w', 'na', 'do', 'z', 'jest', 'to', 'nie', 'co', 'jak', 'dla', 'od'],
        ],
        'cs' => [
            'name' => 'Čeština',
            'path_patterns' => ['/cs/', '/cz/', '/ces/', '/czech/', '/cestina/'],
            'tlds' => ['.cz'],
            'sku_patterns' => ['-CS', '_CS', '-CZ', '_CZ', '-cs', '_cs', '/CZ', '/cs'],
            'common_words' => ['a', 'je', 'v', 'na', 'se', 'že', 'to', 'jako', 'pro', 'z', 'ale', 'by'],
        ],
        'sk' => [
            'name' => 'Slovenčina',
            'path_patterns' => ['/sk/', '/slk/', '/slovak/', '/slovencina/'],
            'tlds' => ['.sk'],
            'sku_patterns' => ['-SK', '_SK', '-sk', '_sk', '/SK', '/sk'],
            'common_words' => ['a', 'je', 'v', 'na', 'sa', 'že', 'to', 'ako', 'pre', 'z', 'ale', 'by'],
        ],
        'hu' => [
            'name' => 'Magyar',
            'path_patterns' => ['/hu/', '/hun/', '/hungarian/', '/magyar/'],
            'tlds' => ['.hu'],
            'sku_patterns' => ['-HU', '_HU', '-hu', '_hu', '/HU', '/hu'],
            'common_words' => ['a', 'az', 'és', 'hogy', 'nem', 'is', 'van', 'volt', 'meg', 'már', 'csak', 'egy'],
        ],
        'ro' => [
            'name' => 'Română',
            'path_patterns' => ['/ro/', '/ron/', '/romanian/', '/romana/'],
            'tlds' => ['.ro', '.md'],
            'sku_patterns' => ['-RO', '_RO', '-ro', '_ro', '/RO', '/ro'],
            'common_words' => ['și', 'de', 'în', 'la', 'pe', 'un', 'o', 'cu', 'nu', 'este', 'pentru', 'care'],
        ],
        'bg' => [
            'name' => 'Български',
            'path_patterns' => ['/bg/', '/bg-bg/', '/bul/', '/bulgarian/'],
            'tlds' => ['.bg'],
            'sku_patterns' => ['-BG', '_BG', '-bg', '_bg', '/BG', '/bg'],
            'common_words' => ['и', 'на', 'за', 'от', 'с', 'е', 'в', 'се', 'да', 'не', 'по', 'при', 'до'],
        ],
        'hr' => [
            'name' => 'Hrvatski',
            'path_patterns' => ['/hr/', '/hrv/', '/croatian/', '/hrvatski/'],
            'tlds' => ['.hr'],
            'sku_patterns' => ['-HR', '_HR', '-hr', '_hr', '/HR', '/hr'],
            'common_words' => ['i', 'je', 'u', 'da', 'na', 'se', 'za', 'od', 'ali', 'ne', 'su', 'što'],
        ],
        'sr' => [
            'name' => 'Srpski',
            'path_patterns' => ['/sr/', '/srp/', '/serbian/', '/srpski/'],
            'tlds' => ['.rs'],
            'sku_patterns' => ['-SR', '_SR', '-sr', '_sr', '-RS', '/RS', '/sr'],
            'common_words' => ['и', 'је', 'у', 'да', 'на', 'се', 'за', 'од', 'али', 'не', 'су', 'што'],
        ],
        'sl' => [
            'name' => 'Slovenščina',
            'path_patterns' => ['/sl/', '/slv/', '/slovenian/', '/slovenscina/'],
            'tlds' => ['.si'],
            'sku_patterns' => ['-SL', '_SL', '-sl', '_sl', '-SI', '/SI', '/sl'],
            'common_words' => ['in', 'je', 'v', 'da', 'na', 'se', 'za', 'od', 'ki', 'ne', 'so', 'kot'],
        ],
        'mk' => [
            'name' => 'Македонски',
            'path_patterns' => ['/mk/', '/mkd/', '/macedonian/'],
            'tlds' => ['.mk'],
            'sku_patterns' => ['-MK', '_MK', '-mk', '_mk', '/MK', '/mk'],
            'common_words' => ['и', 'на', 'во', 'од', 'за', 'се', 'со', 'не', 'да', 'е', 'што', 'тоа'],
        ],
        'sq' => [
            'name' => 'Shqip',
            'path_patterns' => ['/sq/', '/alb/', '/albanian/', '/shqip/'],
            'tlds' => ['.al'],
            'sku_patterns' => ['-SQ', '_SQ', '-sq', '_sq', '-AL', '/AL', '/sq'],
            'common_words' => ['dhe', 'në', 'të', 'për', 'me', 'nga', 'që', 'është', 'nuk', 'ka', 'ose', 'si'],
        ],

        // Baltic
        'lt' => [
            'name' => 'Lietuvių',
            'path_patterns' => ['/lt/', '/lit/', '/lithuanian/', '/lietuviu/'],
            'tlds' => ['.lt'],
            'sku_patterns' => ['-LT', '_LT', '-lt', '_lt', '/LT', '/lt'],
            'common_words' => ['ir', 'yra', 'kad', 'su', 'tai', 'iš', 'buvo', 'jis', 'bet', 'ar', 'ne', 'dar'],
        ],
        'lv' => [
            'name' => 'Latviešu',
            'path_patterns' => ['/lv/', '/lav/', '/latvian/', '/latviesu/'],
            'tlds' => ['.lv'],
            'sku_patterns' => ['-LV', '_LV', '-lv', '_lv', '/LV', '/lv'],
            'common_words' => ['un', 'ir', 'ka', 'ar', 'no', 'bet', 'tas', 'lai', 'vai', 'par', 'uz', 'gan'],
        ],
        'et' => [
            'name' => 'Eesti',
            'path_patterns' => ['/et/', '/est/', '/estonian/', '/eesti/'],
            'tlds' => ['.ee'],
            'sku_patterns' => ['-ET', '_ET', '-et', '_et', '-EE', '/EE', '/et'],
            'common_words' => ['ja', 'on', 'et', 'ei', 'ta', 'see', 'aga', 'mis', 'kui', 'oli', 'ka', 'siis'],
        ],

        // Eastern Europe & Russia
        'ru' => [
            'name' => 'Русский',
            'path_patterns' => ['/ru/', '/ru-ru/', '/rus/', '/russian/'],
            'tlds' => ['.ru', '.su', '.рф'],
            'sku_patterns' => ['-RU', '_RU', '-ru', '_ru', '/RU', '/ru'],
            'common_words' => ['и', 'в', 'на', 'с', 'для', 'это', 'что', 'как', 'по', 'не', 'из', 'от'],
        ],
        'uk' => [
            'name' => 'Українська',
            'path_patterns' => ['/uk/', '/ukr/', '/ukrainian/', '/ukrainska/'],
            'tlds' => ['.ua', '.укр'],
            'sku_patterns' => ['-UK', '_UK', '-uk', '_uk', '-UA', '/UA', '/uk'],
            'common_words' => ['і', 'в', 'на', 'з', 'для', 'це', 'що', 'як', 'не', 'до', 'та', 'є'],
        ],
        'be' => [
            'name' => 'Беларуская',
            'path_patterns' => ['/be/', '/bel/', '/belarusian/'],
            'tlds' => ['.by', '.бел'],
            'sku_patterns' => ['-BE', '_BE', '-be', '_be', '-BY', '/BY', '/be'],
            'common_words' => ['і', 'у', 'на', 'з', 'для', 'гэта', 'што', 'як', 'не', 'да', 'ад', 'па'],
        ],

        // Others in Europe
        'el' => [
            'name' => 'Ελληνικά',
            'path_patterns' => ['/el/', '/ell/', '/greek/', '/ellinika/'],
            'tlds' => ['.gr', '.ελ'],
            'sku_patterns' => ['-EL', '_EL', '-el', '_el', '-GR', '/GR', '/el'],
            'common_words' => ['και', 'το', 'να', 'της', 'του', 'με', 'για', 'είναι', 'τα', 'στο', 'από', 'που'],
        ],
        'tr' => [
            'name' => 'Türkçe',
            'path_patterns' => ['/tr/', '/tur/', '/turkish/', '/turkce/'],
            'tlds' => ['.tr'],
            'sku_patterns' => ['-TR', '_TR', '-tr', '_tr', '/TR', '/tr'],
            'common_words' => ['ve', 'bir', 'bu', 'da', 'için', 'ile', 'olarak', 'en', 'olan', 'değil', 'var', 'daha'],
        ],
        'mt' => [
            'name' => 'Malti',
            'path_patterns' => ['/mt/', '/mlt/', '/maltese/', '/malti/'],
            'tlds' => ['.mt'],
            'sku_patterns' => ['-MT', '_MT', '-mt', '_mt', '/MT', '/mt'],
            'common_words' => ['u', 'li', 'ta', 'il', 'ma', 'għal', 'fil', 'minn', 'din', 'jew', 'dan', 'biex'],
        ],
        'ga' => [
            'name' => 'Gaeilge',
            'path_patterns' => ['/ga/', '/gle/', '/irish/', '/gaeilge/'],
            'tlds' => [],
            'sku_patterns' => ['-GA', '_GA', '-ga', '_ga'],
            'common_words' => ['agus', 'ar', 'an', 'le', 'sé', 'go', 'na', 'sin', 'chun', 'bhí', 'atá', 'ach'],
        ],
        'cy' => [
            'name' => 'Cymraeg',
            'path_patterns' => ['/cy/', '/cym/', '/welsh/', '/cymraeg/'],
            'tlds' => ['.cymru', '.wales'],
            'sku_patterns' => ['-CY', '_CY', '-cy', '_cy'],
            'common_words' => ['a', 'yn', 'i', 'ar', 'y', 'o', 'am', 'yr', 'ei', 'mae', 'ond', 'nad'],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────
    // ASIA - Langues asiatiques
    // ─────────────────────────────────────────────────────────────────
    private const ASIA_LOCALES = [
        // East Asia
        'zh' => [
            'name' => '中文 (简体)',
            'path_patterns' => ['/zh/', '/zh-cn/', '/chn/', '/chinese/', '/cn/'],
            'tlds' => ['.cn', '.中国'],
            'sku_patterns' => ['-ZH', '_ZH', '-zh', '_zh', '-CN', '/CN', '/zh'],
            'common_words' => ['的', '是', '在', '和', '了', '有', '不', '为', '这', '我', '你', '他'],
        ],
        'zh-tw' => [
            'name' => '中文 (繁體)',
            'path_patterns' => ['/zh-tw/', '/zh-hant/', '/tw/', '/taiwan/'],
            'tlds' => ['.tw', '.台灣'],
            'sku_patterns' => ['-TW', '_TW', '-tw', '_tw'],
            'common_words' => ['的', '是', '在', '和', '了', '有', '不', '為', '這', '我', '你', '他'],
        ],
        'ja' => [
            'name' => '日本語',
            'path_patterns' => ['/ja/', '/jp/', '/jpn/', '/japanese/', '/nihongo/'],
            'tlds' => ['.jp', '.日本'],
            'sku_patterns' => ['-JA', '_JA', '-ja', '_ja', '-JP', '/JP', '/ja'],
            'common_words' => ['の', 'は', 'を', 'に', 'が', 'で', 'と', 'た', 'も', 'です', 'ます', 'する'],
        ],
        'ko' => [
            'name' => '한국어',
            'path_patterns' => ['/ko/', '/kr/', '/kor/', '/korean/', '/hanguk/'],
            'tlds' => ['.kr', '.한국'],
            'sku_patterns' => ['-KO', '_KO', '-ko', '_ko', '-KR', '/KR', '/ko'],
            'common_words' => ['의', '을', '이', '가', '에', '는', '를', '으로', '와', '한', '그', '수'],
        ],
        'mn' => [
            'name' => 'Монгол',
            'path_patterns' => ['/mn/', '/mon/', '/mongolian/'],
            'tlds' => ['.mn'],
            'sku_patterns' => ['-MN', '_MN', '-mn', '_mn', '/MN', '/mn'],
            'common_words' => ['ба', 'нь', 'эн', 'та', 'юм', 'бол', 'гэж', 'ч', 'дээр', 'их', 'байна'],
        ],

        // Southeast Asia
        'th' => [
            'name' => 'ไทย',
            'path_patterns' => ['/th/', '/tha/', '/thai/', '/thailand/'],
            'tlds' => ['.th', '.ไทย'],
            'sku_patterns' => ['-TH', '_TH', '-th', '_th', '/TH', '/th'],
            'common_words' => ['ที่', 'และ', 'ใน', 'ของ', 'เป็น', 'ได้', 'มี', 'จะ', 'ไม่', 'นี้', 'ก็', 'ให้'],
        ],
        'vi' => [
            'name' => 'Tiếng Việt',
            'path_patterns' => ['/vi/', '/vie/', '/vietnamese/', '/tiengviet/'],
            'tlds' => ['.vn'],
            'sku_patterns' => ['-VI', '_VI', '-vi', '_vi', '-VN', '/VN', '/vi'],
            'common_words' => ['của', 'và', 'là', 'có', 'trong', 'được', 'cho', 'này', 'với', 'không', 'một', 'những'],
        ],
        'id' => [
            'name' => 'Bahasa Indonesia',
            'path_patterns' => ['/id/', '/ind/', '/indonesian/', '/bahasa/'],
            'tlds' => ['.id'],
            'sku_patterns' => ['-ID', '_ID', '-id', '_id', '/ID', '/id'],
            'common_words' => ['yang', 'dan', 'di', 'untuk', 'ini', 'dengan', 'dari', 'adalah', 'pada', 'ke', 'tidak', 'juga'],
        ],
        'ms' => [
            'name' => 'Bahasa Melayu',
            'path_patterns' => ['/ms/', '/may/', '/malay/', '/melayu/'],
            'tlds' => ['.my', '.bn'],
            'sku_patterns' => ['-MS', '_MS', '-ms', '_ms', '-MY', '/MY', '/ms'],
            'common_words' => ['yang', 'dan', 'di', 'untuk', 'ini', 'dengan', 'dari', 'adalah', 'pada', 'ke', 'tidak', 'juga'],
        ],
        'tl' => [
            'name' => 'Tagalog',
            'path_patterns' => ['/tl/', '/fil/', '/tagalog/', '/filipino/'],
            'tlds' => ['.ph'],
            'sku_patterns' => ['-TL', '_TL', '-tl', '_tl', '-PH', '/PH', '/tl'],
            'common_words' => ['ang', 'ng', 'sa', 'na', 'at', 'ay', 'mga', 'ito', 'ni', 'para', 'ko', 'ka'],
        ],
        'km' => [
            'name' => 'ភាសាខ្មែរ',
            'path_patterns' => ['/km/', '/khm/', '/khmer/', '/cambodian/'],
            'tlds' => ['.kh'],
            'sku_patterns' => ['-KM', '_KM', '-km', '_km', '-KH', '/KH', '/km'],
            'common_words' => ['នៅ', 'និង', 'បាន', 'ដែល', 'មួយ', 'ក្នុង', 'ពី', 'សម្រាប់', 'ទៅ', 'នេះ'],
        ],
        'lo' => [
            'name' => 'ລາວ',
            'path_patterns' => ['/lo/', '/lao/', '/laos/'],
            'tlds' => ['.la'],
            'sku_patterns' => ['-LO', '_LO', '-lo', '_lo', '-LA', '/LA', '/lo'],
            'common_words' => ['ໃນ', 'ແລະ', 'ທີ່', 'ໄດ້', 'ເປັນ', 'ນີ້', 'ມີ', 'ໃຫ້', 'ຈະ', 'ບໍ່'],
        ],
        'my' => [
            'name' => 'မြန်မာ',
            'path_patterns' => ['/my/', '/mya/', '/burmese/', '/myanmar/'],
            'tlds' => ['.mm'],
            'sku_patterns' => ['-MY', '_MY', '-my', '_my', '-MM', '/MM'],
            'common_words' => ['သည်', 'က', 'ကို', 'တွင်', 'နှင့်', 'များ', 'မှ', 'ဖြင့်', 'အား', 'သို့'],
        ],

        // South Asia
        'hi' => [
            'name' => 'हिन्दी',
            'path_patterns' => ['/hi/', '/hin/', '/hindi/'],
            'tlds' => ['.in', '.भारत'],
            'sku_patterns' => ['-HI', '_HI', '-hi', '_hi', '-IN', '/IN', '/hi'],
            'common_words' => ['का', 'की', 'के', 'है', 'में', 'को', 'और', 'से', 'एक', 'यह', 'पर', 'ने'],
        ],
        'bn' => [
            'name' => 'বাংলা',
            'path_patterns' => ['/bn/', '/ben/', '/bengali/', '/bangla/'],
            'tlds' => ['.bd', '.বাংলা'],
            'sku_patterns' => ['-BN', '_BN', '-bn', '_bn', '-BD', '/BD', '/bn'],
            'common_words' => ['এ', 'ও', 'করা', 'করে', 'হয়', 'হয়েছে', 'যে', 'এই', 'থেকে', 'তার', 'একটি'],
        ],
        'ta' => [
            'name' => 'தமிழ்',
            'path_patterns' => ['/ta/', '/tam/', '/tamil/'],
            'tlds' => [],
            'sku_patterns' => ['-TA', '_TA', '-ta', '_ta'],
            'common_words' => ['ஒரு', 'என்று', 'மற்றும்', 'இந்த', 'என்ற', 'அவர்', 'இது', 'அது', 'உள்ள', 'கொண்ட'],
        ],
        'te' => [
            'name' => 'తెలుగు',
            'path_patterns' => ['/te/', '/tel/', '/telugu/'],
            'tlds' => [],
            'sku_patterns' => ['-TE', '_TE', '-te', '_te'],
            'common_words' => ['ఒక', 'మరియు', 'ఈ', 'కొరకు', 'అని', 'చేసిన', 'ద్వారా', 'లో', 'అతను', 'అది'],
        ],
        'mr' => [
            'name' => 'मराठी',
            'path_patterns' => ['/mr/', '/mar/', '/marathi/'],
            'tlds' => [],
            'sku_patterns' => ['-MR', '_MR', '-mr', '_mr'],
            'common_words' => ['आणि', 'हे', 'या', 'आहे', 'च्या', 'एक', 'असे', 'केले', 'मध्ये', 'त्या'],
        ],
        'gu' => [
            'name' => 'ગુજરાતી',
            'path_patterns' => ['/gu/', '/guj/', '/gujarati/'],
            'tlds' => [],
            'sku_patterns' => ['-GU', '_GU', '-gu', '_gu'],
            'common_words' => ['અને', 'છે', 'આ', 'એક', 'માટે', 'કે', 'તે', 'ના', 'ની', 'હતી', 'થી'],
        ],
        'pa' => [
            'name' => 'ਪੰਜਾਬੀ',
            'path_patterns' => ['/pa/', '/pan/', '/punjabi/'],
            'tlds' => ['.pk'],
            'sku_patterns' => ['-PA', '_PA', '-pa', '_pa', '-PK', '/PK'],
            'common_words' => ['ਅਤੇ', 'ਹੈ', 'ਇਸ', 'ਇੱਕ', 'ਲਈ', 'ਕਿ', 'ਉਸ', 'ਨੂੰ', 'ਨਾਲ', 'ਹੋ'],
        ],
        'si' => [
            'name' => 'සිංහල',
            'path_patterns' => ['/si/', '/sin/', '/sinhala/', '/sinhalese/'],
            'tlds' => ['.lk'],
            'sku_patterns' => ['-SI', '_SI', '-si', '_si', '-LK', '/LK'],
            'common_words' => ['සහ', 'ය', 'එය', 'මෙම', 'ඇත', 'සඳහා', 'ඔහු', 'ඇති', 'වේ', 'නොවේ'],
        ],
        'ne' => [
            'name' => 'नेपाली',
            'path_patterns' => ['/ne/', '/nep/', '/nepali/'],
            'tlds' => ['.np'],
            'sku_patterns' => ['-NE', '_NE', '-ne', '_ne', '-NP', '/NP'],
            'common_words' => ['र', 'छ', 'यो', 'हो', 'भएको', 'गर्ने', 'थियो', 'एक', 'मा', 'को'],
        ],
        'ur' => [
            'name' => 'اردو',
            'path_patterns' => ['/ur/', '/urd/', '/urdu/'],
            'tlds' => [],
            'sku_patterns' => ['-UR', '_UR', '-ur', '_ur'],
            'common_words' => ['اور', 'ہے', 'کی', 'کے', 'میں', 'کو', 'نے', 'سے', 'یہ', 'ایک'],
        ],

        // Central Asia
        'kk' => [
            'name' => 'Қазақша',
            'path_patterns' => ['/kk/', '/kaz/', '/kazakh/'],
            'tlds' => ['.kz'],
            'sku_patterns' => ['-KK', '_KK', '-kk', '_kk', '-KZ', '/KZ'],
            'common_words' => ['және', 'бұл', 'бір', 'үшін', 'болып', 'оның', 'деп', 'сондай', 'мен', 'жылы'],
        ],
        'uz' => [
            'name' => 'Oʻzbek',
            'path_patterns' => ['/uz/', '/uzb/', '/uzbek/'],
            'tlds' => ['.uz'],
            'sku_patterns' => ['-UZ', '_UZ', '-uz', '_uz', '/UZ', '/uz'],
            'common_words' => ['va', 'bu', 'bir', 'uchun', 'bilan', 'esa', 'ham', 'bo\'lib', 'qadar', 'kerak'],
        ],
        'ky' => [
            'name' => 'Кыргызча',
            'path_patterns' => ['/ky/', '/kir/', '/kyrgyz/'],
            'tlds' => ['.kg'],
            'sku_patterns' => ['-KY', '_KY', '-ky', '_ky', '-KG', '/KG'],
            'common_words' => ['жана', 'бул', 'бир', 'үчүн', 'менен', 'болуп', 'анын', 'деп', 'мен', 'жылы'],
        ],
        'tg' => [
            'name' => 'Тоҷикӣ',
            'path_patterns' => ['/tg/', '/tgk/', '/tajik/'],
            'tlds' => ['.tj'],
            'sku_patterns' => ['-TG', '_TG', '-tg', '_tg', '-TJ', '/TJ'],
            'common_words' => ['ва', 'дар', 'ба', 'бо', 'аз', 'ки', 'ин', 'барои', 'шуд', 'мебошад'],
        ],
        'tk' => [
            'name' => 'Türkmençe',
            'path_patterns' => ['/tk/', '/tuk/', '/turkmen/'],
            'tlds' => ['.tm'],
            'sku_patterns' => ['-TK', '_TK', '-tk', '_tk', '-TM', '/TM'],
            'common_words' => ['we', 'bu', 'bir', 'üçin', 'bilen', 'bolup', 'hem', 'diýip', 'ol', 'däl'],
        ],

        // Middle East
        'ar' => [
            'name' => 'العربية',
            'path_patterns' => ['/ar/', '/ara/', '/arabic/'],
            'tlds' => ['.sa', '.ae', '.eg', '.ma', '.dz', '.tn', '.عرب'],
            'sku_patterns' => ['-AR', '_AR', '-ar', '_ar', '-SA', '/SA', '-AE', '/AE'],
            'common_words' => ['من', 'في', 'على', 'إلى', 'أن', 'ما', 'هذا', 'مع', 'لا', 'هو', 'التي', 'كان'],
        ],
        'fa' => [
            'name' => 'فارسی',
            'path_patterns' => ['/fa/', '/fas/', '/persian/', '/farsi/'],
            'tlds' => ['.ir', '.ایران'],
            'sku_patterns' => ['-FA', '_FA', '-fa', '_fa', '-IR', '/IR'],
            'common_words' => ['و', 'در', 'به', 'از', 'که', 'این', 'را', 'با', 'است', 'یک', 'برای', 'آن'],
        ],
        'he' => [
            'name' => 'עברית',
            'path_patterns' => ['/he/', '/heb/', '/hebrew/', '/ivrit/'],
            'tlds' => ['.il'],
            'sku_patterns' => ['-HE', '_HE', '-he', '_he', '-IL', '/IL'],
            'common_words' => ['של', 'את', 'על', 'הוא', 'לא', 'זה', 'עם', 'כי', 'גם', 'אל', 'או', 'היה'],
        ],
        'az' => [
            'name' => 'Azərbaycan',
            'path_patterns' => ['/az/', '/aze/', '/azerbaijani/'],
            'tlds' => ['.az'],
            'sku_patterns' => ['-AZ', '_AZ', '-az', '_az', '/AZ'],
            'common_words' => ['və', 'bu', 'bir', 'üçün', 'ilə', 'edir', 'olub', 'var', 'da', 'onun', 'ki'],
        ],
        'ka' => [
            'name' => 'ქართული',
            'path_patterns' => ['/ka/', '/kat/', '/georgian/', '/kartuli/'],
            'tlds' => ['.ge', '.გე'],
            'sku_patterns' => ['-KA', '_KA', '-ka', '_ka', '-GE', '/GE'],
            'common_words' => ['და', 'რომ', 'ის', 'ეს', 'მისი', 'რა', 'თუ', 'არის', 'იყო', 'ერთ', 'ყველა'],
        ],
        'hy' => [
            'name' => 'Armenian',
            'path_patterns' => ['/hy/', '/hye/', '/armenian/', '/hayeren/'],
            'tlds' => ['.am'],
            'sku_patterns' => ['-HY', '_HY', '-hy', '_hy', '-AM', '/AM'],
            'common_words' => ['ev', 'e', 'vor', 'ays', 'het', 'mek', 'hamar', 'ayn', 'ynker', 'inch'],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────
    // AFRICA - Langues africaines
    // ─────────────────────────────────────────────────────────────────
    private const AFRICA_LOCALES = [
        'sw' => [
            'name' => 'Kiswahili',
            'path_patterns' => ['/sw/', '/swa/', '/swahili/', '/kiswahili/'],
            'tlds' => ['.ke', '.tz'],
            'sku_patterns' => ['-SW', '_SW', '-sw', '_sw', '-KE', '-TZ'],
            'common_words' => ['na', 'ya', 'wa', 'kwa', 'ni', 'katika', 'hiyo', 'hii', 'pia', 'au', 'kama', 'kuwa'],
        ],
        'am' => [
            'name' => 'አማርኛ',
            'path_patterns' => ['/am/', '/amh/', '/amharic/'],
            'tlds' => ['.et'],
            'sku_patterns' => ['-AM', '_AM', '-am', '_am', '-ET', '/ET'],
            'common_words' => ['እና', 'ነው', 'በ', 'የ', 'ላይ', 'ከ', 'ለ', 'ስለ', 'ብቻ', 'ግን'],
        ],
        'ha' => [
            'name' => 'Hausa',
            'path_patterns' => ['/ha/', '/hau/', '/hausa/'],
            'tlds' => ['.ng'],
            'sku_patterns' => ['-HA', '_HA', '-ha', '_ha', '-NG', '/NG'],
            'common_words' => ['da', 'na', 'ya', 'ta', 'a', 'shi', 'su', 'za', 'kuma', 'cikin', 'wannan', 'ba'],
        ],
        'yo' => [
            'name' => 'Yorùbá',
            'path_patterns' => ['/yo/', '/yor/', '/yoruba/'],
            'tlds' => [],
            'sku_patterns' => ['-YO', '_YO', '-yo', '_yo'],
            'common_words' => ['ati', 'ni', 'ti', 'fun', 'lati', 'sí', 'pẹ̀lú', 'náà', 'kan', 'wọ́n', 'bí'],
        ],
        'ig' => [
            'name' => 'Igbo',
            'path_patterns' => ['/ig/', '/ibo/', '/igbo/'],
            'tlds' => [],
            'sku_patterns' => ['-IG', '_IG', '-ig', '_ig'],
            'common_words' => ['na', 'ya', 'ọ', 'nke', 'maka', 'n\'oge', 'onye', 'ihe', 'e', 'si'],
        ],
        'zu' => [
            'name' => 'IsiZulu',
            'path_patterns' => ['/zu/', '/zul/', '/zulu/'],
            'tlds' => ['.za'],
            'sku_patterns' => ['-ZU', '_ZU', '-zu', '_zu', '-ZA', '/ZA'],
            'common_words' => ['ukuthi', 'futhi', 'kwa', 'uma', 'ngoba', 'yena', 'bona', 'waye', 'kuyo', 'esho'],
        ],
        'xh' => [
            'name' => 'IsiXhosa',
            'path_patterns' => ['/xh/', '/xho/', '/xhosa/'],
            'tlds' => [],
            'sku_patterns' => ['-XH', '_XH', '-xh', '_xh'],
            'common_words' => ['ukuthi', 'futhi', 'kwa', 'uma', 'ngoba', 'yena', 'bona', 'waye', 'kuyo', 'esho'],
        ],
        'af' => [
            'name' => 'Afrikaans',
            'path_patterns' => ['/af/', '/afr/', '/afrikaans/'],
            'tlds' => [],
            'sku_patterns' => ['-AF', '_AF', '-af', '_af'],
            'common_words' => ['en', 'die', 'van', 'is', 'het', 'wat', 'hy', 'sy', 'nie', 'vir', 'ook', 'maar'],
        ],
        'rw' => [
            'name' => 'Kinyarwanda',
            'path_patterns' => ['/rw/', '/kin/', '/kinyarwanda/'],
            'tlds' => ['.rw'],
            'sku_patterns' => ['-RW', '_RW', '-rw', '_rw', '/RW'],
            'common_words' => ['ni', 'na', 'mu', 'ko', 'kuri', 'nta', 'ariko', 'cyangwa', 'ndetse', 'kandi'],
        ],
        'mg' => [
            'name' => 'Malagasy',
            'path_patterns' => ['/mg/', '/mlg/', '/malagasy/'],
            'tlds' => ['.mg'],
            'sku_patterns' => ['-MG', '_MG', '-mg', '_mg', '/MG'],
            'common_words' => ['ny', 'sy', 'dia', 'ho', 'amin', 'izay', 'ary', 'tsy', 'izy', 'ireo', 'ao', 'an'],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────
    // AMERICAS - Langues des Amériques
    // ─────────────────────────────────────────────────────────────────
    private const AMERICAS_LOCALES = [
        'pt-br' => [
            'name' => 'Português (Brasil)',
            'path_patterns' => ['/pt-br/', '/br/', '/brazil/', '/brasil/'],
            'tlds' => ['.br', '.com.br'],
            'sku_patterns' => ['-BR', '_BR', '-br', '_br', '/BR'],
            'common_words' => ['de', 'e', 'o', 'a', 'que', 'para', 'em', 'com', 'não', 'um', 'uma', 'os'],
        ],
        'es-mx' => [
            'name' => 'Español (México)',
            'path_patterns' => ['/es-mx/', '/mx/', '/mexico/'],
            'tlds' => ['.mx', '.com.mx'],
            'sku_patterns' => ['-MX', '_MX', '-mx', '_mx', '/MX'],
            'common_words' => ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'para', 'por', 'una', 'uno', 'que'],
        ],
        'es-ar' => [
            'name' => 'Español (Argentina)',
            'path_patterns' => ['/es-ar/', '/ar/', '/argentina/'],
            'tlds' => ['.ar', '.com.ar'],
            'sku_patterns' => ['-AR', '_AR', '-ar', '_ar'],
            'common_words' => ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'para', 'por', 'una', 'uno', 'que'],
        ],
        'es-co' => [
            'name' => 'Español (Colombia)',
            'path_patterns' => ['/es-co/', '/co/', '/colombia/'],
            'tlds' => ['.co', '.com.co'],
            'sku_patterns' => ['-CO', '_CO', '-co', '_co'],
            'common_words' => ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'para', 'por', 'una', 'uno', 'que'],
        ],
        'en-us' => [
            'name' => 'English (US)',
            'path_patterns' => ['/en-us/', '/us/', '/american/'],
            'tlds' => ['.com', '.us', '.gov'],
            'sku_patterns' => ['-US', '_US', '-us', '_us', '/US'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have'],
        ],
        'en-ca' => [
            'name' => 'English (Canada)',
            'path_patterns' => ['/en-ca/', '/ca/'],
            'tlds' => ['.ca'],
            'sku_patterns' => ['-CA', '_CA'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have'],
        ],
        'fr-ca' => [
            'name' => 'Français (Canada)',
            'path_patterns' => ['/fr-ca/', '/quebec/'],
            'tlds' => [],
            'sku_patterns' => ['-QC', '_QC'],
            'common_words' => ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'est', 'pour', 'avec'],
        ],
        'ht' => [
            'name' => 'Kreyòl ayisyen',
            'path_patterns' => ['/ht/', '/hat/', '/haitian/', '/creole/'],
            'tlds' => ['.ht'],
            'sku_patterns' => ['-HT', '_HT', '-ht', '_ht', '/HT'],
            'common_words' => ['nan', 'ak', 'pou', 'li', 'yo', 'ki', 'te', 'yon', 'pa', 'gen', 'sa', 'tout'],
        ],
        'qu' => [
            'name' => 'Runasimi (Quechua)',
            'path_patterns' => ['/qu/', '/que/', '/quechua/'],
            'tlds' => [],
            'sku_patterns' => ['-QU', '_QU', '-qu', '_qu'],
            'common_words' => ['hina', 'kay', 'kuna', 'ñan', 'wasi', 'runa', 'mana', 'ima', 'kan', 'chay'],
        ],
        'gn' => [
            'name' => 'Guarani',
            'path_patterns' => ['/gn/', '/grn/', '/guarani/'],
            'tlds' => ['.py'],
            'sku_patterns' => ['-GN', '_GN', '-gn', '_gn', '-PY', '/PY'],
            'common_words' => ['ha', 'pe', 'umi', 'ñane', 'oiko', 'heta', 'ndive', 'aguĩ', 'oĩ', 'mbae'],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────
    // OCEANIA - Langues d'Océanie
    // ─────────────────────────────────────────────────────────────────
    private const OCEANIA_LOCALES = [
        'en-au' => [
            'name' => 'English (Australia)',
            'path_patterns' => ['/en-au/', '/au/', '/australia/'],
            'tlds' => ['.au', '.com.au'],
            'sku_patterns' => ['-AU', '_AU', '-au', '_au', '/AU'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have'],
        ],
        'en-nz' => [
            'name' => 'English (New Zealand)',
            'path_patterns' => ['/en-nz/', '/nz/', '/newzealand/'],
            'tlds' => ['.nz', '.co.nz'],
            'sku_patterns' => ['-NZ', '_NZ', '-nz', '_nz', '/NZ'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have'],
        ],
        'mi' => [
            'name' => 'Te Reo Māori',
            'path_patterns' => ['/mi/', '/mri/', '/maori/'],
            'tlds' => ['.maori.nz'],
            'sku_patterns' => ['-MI', '_MI', '-mi', '_mi'],
            'common_words' => ['te', 'ko', 'ki', 'i', 'he', 'me', 'ka', 'kua', 'e', 'ngā', 'hoki', 'rā'],
        ],
        'haw' => [
            'name' => 'ʻŌlelo Hawaiʻi',
            'path_patterns' => ['/haw/', '/hawaiian/'],
            'tlds' => [],
            'sku_patterns' => ['-HAW', '_HAW', '-haw', '_haw'],
            'common_words' => ['a', 'i', 'ka', 'ke', 'o', 'ʻo', 'ua', 'me', 'na', 'nā', 'ko', 'kēia'],
        ],
        'sm' => [
            'name' => 'Gagana Samoa',
            'path_patterns' => ['/sm/', '/smo/', '/samoan/'],
            'tlds' => ['.ws'],
            'sku_patterns' => ['-SM', '_SM', '-sm', '_sm', '-WS', '/WS'],
            'common_words' => ['ma', 'le', 'o', 'i', 'a', 'ua', 'na', 'e', 'sa', 'lo', 'ai', 'foi'],
        ],
        'to' => [
            'name' => 'Lea fakatonga',
            'path_patterns' => ['/to/', '/ton/', '/tongan/'],
            'tlds' => ['.to'],
            'sku_patterns' => ['-TO', '_TO', '-to', '_to', '/TO'],
            'common_words' => ['ko', 'ki', 'he', 'mo', 'ia', 'ni', 'na', 'e', 'kuo', 'pea', 'ne', 'ʻa'],
        ],
        'fj' => [
            'name' => 'Vakaviti',
            'path_patterns' => ['/fj/', '/fij/', '/fijian/'],
            'tlds' => ['.fj'],
            'sku_patterns' => ['-FJ', '_FJ', '-fj', '_fj', '/FJ'],
            'common_words' => ['na', 'ka', 'ko', 'me', 'kei', 'ni', 'ena', 'se', 'e', 'a', 'mai', 'sa'],
        ],
    ];

    /**
     * All locales combined.
     */
    private static ?array $allLocales = null;

    /**
     * Get all supported locales merged from all regions.
     */
    private static function getAllLocales(): array
    {
        if (self::$allLocales === null) {
            self::$allLocales = array_merge(
                self::EUROPE_LOCALES,
                self::ASIA_LOCALES,
                self::AFRICA_LOCALES,
                self::AMERICAS_LOCALES,
                self::OCEANIA_LOCALES
            );
        }
        return self::$allLocales;
    }

    /**
     * Detect locale from URL.
     */
    public function detectFromUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $originalUrl = $url;
        $url = strtolower($url);
        $parsedUrl = parse_url($url);

        // Check query parameters first (most reliable for API URLs)
        // Supports: locale=fr-FR, lang=fr, language=french, country=fr
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);

            // Check locale parameter (e.g., locale=fr-FR, locale=fr)
            if (!empty($queryParams['locale'])) {
                $localeParam = strtolower($queryParams['locale']);
                $detected = $this->normalizeLocaleCode($localeParam);
                if ($detected) {
                    return $detected;
                }
            }

            // Check lang parameter (e.g., lang=fr, lang=en)
            if (!empty($queryParams['lang'])) {
                $langParam = strtolower($queryParams['lang']);
                $detected = $this->normalizeLocaleCode($langParam);
                if ($detected) {
                    return $detected;
                }
            }

            // Check language parameter (e.g., language=french)
            if (!empty($queryParams['language'])) {
                $languageParam = strtolower($queryParams['language']);
                $detected = $this->normalizeLocaleCode($languageParam);
                if ($detected) {
                    return $detected;
                }
            }

            // Check country parameter as fallback (e.g., country=fr)
            if (!empty($queryParams['country'])) {
                $countryParam = strtolower($queryParams['country']);
                // Country codes often match locale codes for major languages
                if (isset(self::getAllLocales()[$countryParam])) {
                    return $countryParam;
                }
            }
        }

        // Check path patterns (most reliable for regular URLs)
        foreach (self::getAllLocales() as $locale => $patterns) {
            foreach ($patterns['path_patterns'] as $pattern) {
                if (str_contains($url, $pattern)) {
                    return $locale;
                }
            }
        }

        // Check TLD (less reliable, some sites use .com for all languages)
        $host = $parsedUrl['host'] ?? '';

        foreach (self::getAllLocales() as $locale => $patterns) {
            foreach ($patterns['tlds'] as $tld) {
                if (str_ends_with($host, $tld)) {
                    // Skip .com as it's too generic
                    if ($tld !== '.com') {
                        return $locale;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalize a locale code to our supported format.
     * Handles formats like: fr-FR, fr_FR, fr, french, français
     */
    private function normalizeLocaleCode(string $code): ?string
    {
        $code = strtolower(trim($code));

        // Direct match (fr, en, de, bg, etc.)
        if (isset(self::getAllLocales()[$code])) {
            return $code;
        }

        // Handle hyphenated codes (fr-FR -> fr, en-US -> en-us or en)
        if (str_contains($code, '-')) {
            // First check if full code exists (zh-tw, pt-br, en-us, etc.)
            $normalizedCode = str_replace('_', '-', $code);
            if (isset(self::getAllLocales()[$normalizedCode])) {
                return $normalizedCode;
            }

            // Try base language code
            $baseLang = explode('-', $code)[0];
            if (isset(self::getAllLocales()[$baseLang])) {
                return $baseLang;
            }
        }

        // Handle underscored codes (fr_FR -> fr)
        if (str_contains($code, '_')) {
            $baseLang = explode('_', $code)[0];
            if (isset(self::getAllLocales()[$baseLang])) {
                return $baseLang;
            }
        }

        return null;
    }

    /**
     * Detect locale from SKU.
     */
    public function detectFromSku(?string $sku): ?string
    {
        if (empty($sku)) {
            return null;
        }

        foreach (self::getAllLocales() as $locale => $patterns) {
            foreach ($patterns['sku_patterns'] as $pattern) {
                if (str_contains($sku, $pattern)) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * Detect locale from text content (description, name).
     * Uses simple word frequency analysis.
     */
    public function detectFromContent(?string $text): ?string
    {
        if (empty($text) || strlen($text) < 20) {
            return null;
        }

        // First, try to detect from HTML lang attribute if this looks like HTML
        if (str_contains($text, '<html') || str_contains($text, '<!DOCTYPE')) {
            $htmlLang = $this->detectFromHtmlLangAttribute($text);
            if ($htmlLang) {
                return $htmlLang;
            }
        }

        // Then, try script-based detection for non-Latin scripts
        $scriptLocale = $this->detectFromScript($text);
        if ($scriptLocale) {
            return $scriptLocale;
        }

        $text = mb_strtolower($text, 'UTF-8');
        $words = preg_split('/\s+/u', $text);
        $wordCount = count($words);

        if ($wordCount < 5) {
            return null;
        }

        $scores = [];

        foreach (self::getAllLocales() as $locale => $patterns) {
            $matchCount = 0;
            foreach ($patterns['common_words'] as $commonWord) {
                // Count occurrences of common word (with word boundaries for unicode)
                $matchCount += preg_match_all('/(?<![\\p{L}])' . preg_quote($commonWord, '/') . '(?![\\p{L}])/ui', $text);
            }
            // Normalize by number of common words to check
            $scores[$locale] = $matchCount / count($patterns['common_words']);
        }

        // Find best match
        arsort($scores);
        $bestLocale = array_key_first($scores);
        $bestScore = $scores[$bestLocale];

        // Lower threshold for detection (at least some matches)
        if ($bestScore >= 0.2) {
            return $bestLocale;
        }

        return null;
    }

    /**
     * Detect language from HTML lang attribute.
     * Looks for <html lang="xx"> or <html lang="xx-YY">
     */
    public function detectFromHtmlLangAttribute(?string $html): ?string
    {
        if (empty($html)) {
            Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Empty HTML');
            return null;
        }

        Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Processing HTML', [
            'html_length' => strlen($html),
            'contains_html_tag' => str_contains($html, '<html'),
            'contains_lang' => str_contains($html, 'lang='),
            'preview' => mb_substr($html, 0, 200),
        ]);

        // Match lang attribute on html tag: <html lang="fr"> or <html dir="ltr" lang="hr-BA">
        if (preg_match('/<html[^>]*\slang=["\']([a-zA-Z]{2,3}(?:-[a-zA-Z]{2,4})?)["\'][^>]*>/i', $html, $matches)) {
            $langCode = strtolower($matches[1]);
            Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Found lang attribute', [
                'lang_code' => $langCode,
                'full_match' => $matches[0],
            ]);

            // Normalize: hr-BA -> hr, en-US -> en-us, etc.
            // First check if the full code exists (like zh-tw, pt-br)
            if (isset(self::getAllLocales()[$langCode])) {
                Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Found exact locale', ['locale' => $langCode]);
                return $langCode;
            }

            // Then try the base language code (hr-BA -> hr)
            $baseLang = explode('-', $langCode)[0];
            if (isset(self::getAllLocales()[$baseLang])) {
                Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Found base locale', ['locale' => $baseLang, 'original' => $langCode]);
                return $baseLang;
            }

            // Check for regional variants we support (en-us, pt-br, etc.)
            $regionalVariants = ['en-us', 'en-gb', 'en-au', 'en-nz', 'en-ca', 'pt-br', 'es-mx', 'es-ar', 'es-co', 'fr-ca', 'zh-tw'];
            if (in_array($langCode, $regionalVariants)) {
                Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Found regional variant', ['locale' => $langCode]);
                return $langCode;
            }

            // Return base language if it's a valid locale
            if (isset(self::getAllLocales()[$baseLang])) {
                Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Falling back to base locale', ['locale' => $baseLang]);
                return $baseLang;
            }

            Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Lang code not in allowed locales', [
                'lang_code' => $langCode,
                'base_lang' => $baseLang,
            ]);
        } else {
            Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Regex did not match', [
                'contains_html_tag' => str_contains($html, '<html'),
                'contains_lang' => str_contains($html, 'lang='),
            ]);
        }

        // Also check meta http-equiv Content-Language
        if (preg_match('/<meta[^>]*http-equiv=["\']Content-Language["\'][^>]*content=["\']([a-zA-Z]{2,3}(?:-[a-zA-Z]{2,4})?)["\'][^>]*>/i', $html, $matches)) {
            $langCode = strtolower($matches[1]);
            $baseLang = explode('-', $langCode)[0];
            if (isset(self::getAllLocales()[$baseLang])) {
                Log::debug('LanguageDetector::detectFromHtmlLangAttribute: Found Content-Language meta', ['locale' => $baseLang]);
                return $baseLang;
            }
        }

        Log::debug('LanguageDetector::detectFromHtmlLangAttribute: No language detected');
        return null;
    }

    /**
     * Detect language based on Unicode script (Cyrillic, Greek, Arabic, etc.)
     */
    private function detectFromScript(string $text): ?string
    {
        // Count characters by script
        $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $text);
        $greekCount = preg_match_all('/[\p{Greek}]/u', $text);
        $arabicCount = preg_match_all('/[\p{Arabic}]/u', $text);
        $hebrewCount = preg_match_all('/[\p{Hebrew}]/u', $text);
        $thaiCount = preg_match_all('/[\p{Thai}]/u', $text);
        $hangulCount = preg_match_all('/[\p{Hangul}]/u', $text);
        $hiraganaCount = preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $text);
        $hanCount = preg_match_all('/[\p{Han}]/u', $text);
        $georgianCount = preg_match_all('/[\p{Georgian}]/u', $text);
        $armenianCount = preg_match_all('/[\p{Armenian}]/u', $text);

        $minChars = 3; // Minimum characters to consider

        // Cyrillic scripts (Bulgarian, Russian, Ukrainian, etc.)
        if ($cyrillicCount >= $minChars) {
            // Try to distinguish between Cyrillic languages using common words
            $bgScore = preg_match_all('/\b(и|на|за|от|се|да|не|по|при|до|е|в|с)\b/u', $text);
            $ruScore = preg_match_all('/\b(и|в|на|с|для|это|что|как|по|не|из|от)\b/u', $text);
            $ukScore = preg_match_all('/\b(і|в|на|з|для|це|що|як|не|до|та|є)\b/u', $text);

            if ($bgScore >= $ruScore && $bgScore >= $ukScore) {
                return 'bg';
            } elseif ($ukScore > $ruScore) {
                return 'uk';
            } else {
                return 'ru';
            }
        }

        if ($greekCount >= $minChars) return 'el';
        if ($arabicCount >= $minChars) return 'ar';
        if ($hebrewCount >= $minChars) return 'he';
        if ($thaiCount >= $minChars) return 'th';
        if ($hangulCount >= $minChars) return 'ko';
        if ($hiraganaCount >= $minChars) return 'ja';
        if ($hanCount >= $minChars) return 'zh';
        if ($georgianCount >= $minChars) return 'ka';
        if ($armenianCount >= $minChars) return 'hy';

        return null;
    }

    /**
     * Detect locale using all available sources.
     * Priority: URL > SKU > Content
     *
     * @param string|null $url Source URL
     * @param string|null $sku Product SKU
     * @param string|null $content Text content (description)
     * @param array $config Detection configuration from catalog
     */
    public function detect(
        ?string $url = null,
        ?string $sku = null,
        ?string $content = null,
        array $config = []
    ): ?string {
        // Check if detection is enabled
        if (isset($config['enabled']) && !$config['enabled']) {
            Log::debug('LanguageDetector::detect: Detection disabled, using default', ['default' => $config['default_locale'] ?? null]);
            return $config['default_locale'] ?? null;
        }

        // If default locale is forced, use it
        if (!empty($config['default_locale'])) {
            Log::debug('LanguageDetector::detect: Using forced default locale', ['locale' => $config['default_locale']]);
            return $config['default_locale'];
        }

        $methods = $config['methods'] ?? ['url' => true, 'sku' => true, 'content' => true];
        // Empty array or missing = all available locales (allows adding new languages without updating old configs)
        $allowedLocales = !empty($config['allowed_locales']) ? $config['allowed_locales'] : array_keys(self::getAllLocales());

        Log::debug('LanguageDetector::detect: Starting detection', [
            'has_url' => !empty($url),
            'has_sku' => !empty($sku),
            'has_content' => !empty($content),
            'content_length' => strlen($content ?? ''),
            'methods' => $methods,
            'allowed_locales_count' => count($allowedLocales),
            'allowed_locales_sample' => array_slice($allowedLocales, 0, 10),
        ]);

        $locale = null;

        // Try URL first (most reliable)
        if ($methods['url'] ?? true) {
            $locale = $this->detectFromUrl($url);
            Log::debug('LanguageDetector::detect: URL detection result', ['locale' => $locale, 'in_allowed' => $locale ? in_array($locale, $allowedLocales) : null]);
            if ($locale && in_array($locale, $allowedLocales)) {
                return $locale;
            }
        }

        // Try SKU
        if ($methods['sku'] ?? true) {
            $locale = $this->detectFromSku($sku);
            Log::debug('LanguageDetector::detect: SKU detection result', ['locale' => $locale, 'in_allowed' => $locale ? in_array($locale, $allowedLocales) : null]);
            if ($locale && in_array($locale, $allowedLocales)) {
                return $locale;
            }
        }

        // Fall back to content analysis
        if ($methods['content'] ?? true) {
            $locale = $this->detectFromContent($content);
            Log::debug('LanguageDetector::detect: Content detection result', ['locale' => $locale, 'in_allowed' => $locale ? in_array($locale, $allowedLocales) : null]);
            if ($locale && in_array($locale, $allowedLocales)) {
                return $locale;
            }
        }

        Log::debug('LanguageDetector::detect: No locale detected or not in allowed list');
        return null;
    }

    /**
     * Detect locale using simple method (no config).
     * For use in model methods where catalog config is not available.
     */
    public function detectSimple(?string $url = null, ?string $sku = null, ?string $content = null): ?string
    {
        return $this->detect($url, $sku, $content, []);
    }

    /**
     * Get all supported locales.
     */
    public function getSupportedLocales(): array
    {
        return array_keys(self::getAllLocales());
    }

    /**
     * Get locales grouped by continent for display.
     */
    public static function getLocalesByContinent(): array
    {
        return [
            'europe' => [
                'label' => 'Europe',
                'locales' => array_map(fn ($l) => $l['name'], self::EUROPE_LOCALES),
            ],
            'asia' => [
                'label' => 'Asie',
                'locales' => array_map(fn ($l) => $l['name'], self::ASIA_LOCALES),
            ],
            'africa' => [
                'label' => 'Afrique',
                'locales' => array_map(fn ($l) => $l['name'], self::AFRICA_LOCALES),
            ],
            'americas' => [
                'label' => 'Amériques',
                'locales' => array_map(fn ($l) => $l['name'], self::AMERICAS_LOCALES),
            ],
            'oceania' => [
                'label' => 'Océanie',
                'locales' => array_map(fn ($l) => $l['name'], self::OCEANIA_LOCALES),
            ],
        ];
    }

    /**
     * Get human-readable locale name.
     */
    public function getLocaleName(string $locale): string
    {
        $allLocales = self::getAllLocales();
        return $allLocales[$locale]['name'] ?? strtoupper($locale);
    }

    /**
     * Get locale names as array for forms.
     */
    public static function getLocaleNamesForForm(): array
    {
        $result = [];
        foreach (self::getAllLocales() as $code => $data) {
            $result[$code] = $data['name'];
        }
        return $result;
    }
}
