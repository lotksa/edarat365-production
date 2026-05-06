<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\District;
use Illuminate\Database\Seeder;

class SaudiCitiesSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['الرياض', 'Riyadh', 24.7136, 46.6753, [
                ['العليا','Al Olaya'],['الملز','Al Malaz'],['النسيم','Al Naseem'],['الروضة','Al Rawdah'],['السليمانية','As Sulimaniyah'],['المعذر','Al Muathar'],['الورود','Al Wurud'],['الصحافة','As Sahafah'],['الياسمين','Al Yasmin'],['النرجس','An Narjis'],['المروج','Al Muruj'],['الربيع','Ar Rabi'],['الازدهار','Al Izdihar'],['الوادي','Al Wadi'],['الملقا','Al Malqa'],['النخيل','An Nakheel'],['حطين','Hittin'],['الرحمانية','Ar Rahmaniyah'],['الشفا','Ash Shifa'],['العريجاء','Al Urayja'],['المنصورة','Al Mansurah'],['طويق','Tuwaiq'],['السويدي','As Suwaidi'],['الديرة','Ad Dirah'],['البطحاء','Al Batha'],['منفوحة','Manfuhah'],['الفيحاء','Al Faiha'],['المرسلات','Al Mursalat'],['الدار البيضاء','Ad Dar Al Baida'],['النظيم','An Nadheem'],['الجنادرية','Al Janadriyah'],['العقيق','Al Aqiq'],['الغدير','Al Ghadir'],['اشبيلية','Ishbiliyah'],['قرطبة','Qurtubah'],['الحمراء','Al Hamra'],['المصيف','Al Masif'],['الخليج','Al Khalij'],['السلام','As Salam'],['الفلاح','Al Falah'],['المنار','Al Manar'],['المحمدية','Al Muhammadiyah'],['الشهداء','Ash Shuhada'],['العارض','Al Arid'],['الخزامى','Al Khuzama'],['الصفا','As Safa'],['غرناطة','Ghirnatah'],['الندوة','An Nadwah'],['لبن','Laban'],['ظهرة لبن','Dhahrat Laban'],['نمار','Namar'],['عرقة','Irqah'],['الرمال','Ar Rimal'],
            ]],
            ['جدة', 'Jeddah', 21.4858, 39.1925, [
                ['الحمراء','Al Hamra'],['الروضة','Ar Rawdah'],['الزهراء','Az Zahra'],['البساتين','Al Basateen'],['الصفا','As Safa'],['المروة','Al Marwah'],['النزهة','An Nuzhah'],['الشرفية','Ash Sharafiyah'],['البلد','Al Balad'],['السلامة','As Salamah'],['المحمدية','Al Muhammadiyah'],['أبحر الشمالية','Abhur Ash Shamaliyah'],['أبحر الجنوبية','Abhur Al Janubiyah'],['الفيصلية','Al Faisaliyah'],['الأندلس','Al Andalus'],['المرجان','Al Murjan'],['الريان','Ar Rayyan'],['الحمدانية','Al Hamdaniyah'],['طيبة','Taibah'],['النعيم','An Naeem'],['العزيزية','Al Aziziyah'],['مشرفة','Mushrifa'],['الفيحاء','Al Faiha'],['الخالدية','Al Khalidiyah'],['السامر','As Samir'],['بريمان','Briman'],['الواحة','Al Wahah'],['البوادي','Al Bawadi'],['الكورنيش','Al Corniche'],['الربوة','Ar Rabwah'],['الأمير فواز','Al Amir Fawaz'],['المنتزهات','Al Muntazahat'],['الصالحية','As Salhiyah'],['الرحاب','Ar Rehab'],['ذهبان','Dhahban'],['النهضة','An Nahdah'],['الشاطئ','Ash Shati'],['المرسى','Al Marsa'],
            ]],
            ['مكة المكرمة', 'Makkah', 21.3891, 39.8579, [
                ['العزيزية','Al Aziziyah'],['الشوقية','Ash Shawqiyah'],['الرصيفة','Ar Rusayfah'],['النسيم','An Naseem'],['العوالي','Al Awali'],['الزاهر','Az Zahir'],['التيسير','At Tayseer'],['العمرة','Al Umrah'],['الخالدية','Al Khalidiyah'],['المعابدة','Al Maabidah'],['جرهم','Jurhum'],['الهجرة','Al Hijrah'],['بطحاء قريش','Batha Quraish'],['الحمراء','Al Hamra'],['الشهداء','Ash Shuhada'],['التنعيم','At Taneem'],['الككية','Al Kakiyah'],['المسفلة','Al Misfalah'],['الهنداوية','Al Hindawiyah'],['جبل النور','Jabal An Nur'],['العتيبية','Al Utaibiyah'],['الراشدية','Ar Rashidiyah'],
            ]],
            ['المدينة المنورة', 'Madinah', 24.4539, 39.6142, [
                ['العوالي','Al Awali'],['قباء','Quba'],['العنبرية','Al Anbariyah'],['الحرة الشرقية','Al Harra Ash Sharqiyah'],['الحرة الغربية','Al Harra Al Gharbiyah'],['بئر عثمان','Bir Uthman'],['السلام','As Salam'],['الدار','Ad Dar'],['الخالدية','Al Khalidiyah'],['شوران','Shawran'],['الجمعة','Al Jumuah'],['المنشية','Al Manshiyah'],['العزيزية','Al Aziziyah'],['الإسكان','Al Iskan'],['الراية','Ar Rayah'],['النصر','An Nasr'],['قربان','Qurban'],['المبعوث','Al Mabouth'],['العريض','Al Areedh'],['الفتح','Al Fath'],['السيح','As Seeh'],['أبيار علي','Abyar Ali'],
            ]],
            ['الدمام', 'Dammam', 26.4207, 50.0888, [
                ['الفيصلية','Al Faisaliyah'],['الأمير محمد بن سعود','Al Amir M. bin Saud'],['الطبيشي','At Tabishi'],['المزروعية','Al Mazruiyah'],['الجلوية','Al Jalawiyah'],['النخيل','An Nakheel'],['المحمدية','Al Muhammadiyah'],['الشاطئ الغربي','Ash Shati Al Gharbi'],['الشاطئ الشرقي','Ash Shati Ash Sharqi'],['الأنوار','Al Anwar'],['الندى','An Nada'],['الحمراء','Al Hamra'],['العزيزية','Al Aziziyah'],['الجوهرة','Al Jawharah'],['الواحة','Al Wahah'],['الخليج','Al Khalij'],['النورس','An Nawras'],['الأثير','Al Atheer'],['البادية','Al Badiyah'],['الفردوس','Al Firdaws'],['المنار','Al Manar'],['الربيع','Ar Rabi'],['الضباب','Ad Dabab'],['بدر','Badr'],['الريان','Ar Rayyan'],
            ]],
            ['الخبر', 'Khobar', 26.2792, 50.2083, [
                ['العقربية','Al Aqrabiyah'],['الحزام الأخضر','Al Hizam Al Akhdar'],['الثقبة','Ath Thuqbah'],['التحلية','At Tahliyah'],['اليرموك','Al Yarmuk'],['الكورنيش','Al Corniche'],['الروابي','Ar Rawabi'],['العليا','Al Olaya'],['الخزامى','Al Khuzama'],['الراكة الشمالية','Ar Rakah Ash Shamaliyah'],['الراكة الجنوبية','Ar Rakah Al Janubiyah'],['الجسر','Al Jisr'],['الحمراء','Al Hamra'],['الخبر الشمالية','Al Khobar Ash Shamaliyah'],['الخبر الجنوبية','Al Khobar Al Janubiyah'],['البندرية','Al Bandariyah'],['الصفا','As Safa'],['المدينة الرياضية','Sports City'],['اللؤلؤة','Al Lulu'],['البحيرة','Al Buhairah'],
            ]],
            ['الظهران', 'Dhahran', 26.2743, 50.2083, [
                ['الدوحة الشمالية','Ad Doha Ash Shamaliyah'],['الدوحة الجنوبية','Ad Doha Al Janubiyah'],['جامعة الملك فهد','KFUPM'],['دانة','Dana'],['الحي السكني','Residential Area'],
            ]],
            ['الطائف', 'Taif', 21.2703, 40.4158, [
                ['الحوية','Al Hawiyah'],['الشهار','Ash Shahar'],['القيم','Al Qeem'],['السلامة','As Salamah'],['الشفا','Ash Shifa'],['الهدا','Al Hada'],['الفيصلية','Al Faisaliyah'],['نخب','Nakhb'],['الحلقة الشرقية','Al Halaqa Ash Sharqiyah'],['الحلقة الغربية','Al Halaqa Al Gharbiyah'],['الريان','Ar Rayyan'],['العزيزية','Al Aziziyah'],['المثناه','Al Mathnah'],['القمرية','Al Qamariyah'],['شبرا','Shubra'],['المنطقة المركزية','Central District'],
            ]],
            ['تبوك', 'Tabuk', 28.3998, 36.5714, [
                ['المروج','Al Muruj'],['السليمانية','As Sulimaniyah'],['الفيصلية','Al Faisaliyah'],['الروضة','Ar Rawdah'],['المصيف','Al Masif'],['العزيزية','Al Aziziyah'],['السلام','As Salam'],['الربوة','Ar Rabwah'],['النخيل','An Nakheel'],['الورود','Al Wurud'],['الراية','Ar Rayah'],['المحمدية','Al Muhammadiyah'],['الأخضر','Al Akhdar'],
            ]],
            ['بريدة', 'Buraydah', 26.3292, 43.975, [
                ['الخليج','Al Khalij'],['النقع','An Naqa'],['الصفراء','As Safra'],['المنتزه','Al Muntazah'],['الريان','Ar Rayyan'],['الفيصلية','Al Faisaliyah'],['النهضة','An Nahdah'],['السالمية','As Salmiyah'],['الوادي','Al Wadi'],['الحمر','Al Humr'],['الهلالية','Al Hilaliyah'],['الإسكان','Al Iskan'],
            ]],
            ['عنيزة', 'Unayzah', 26.0841, 43.9775, [
                ['السلام','As Salam'],['الفيصلية','Al Faisaliyah'],['النازية','An Naziyah'],['الشفاء','Ash Shifa'],['الجردة','Al Jardah'],['الروابي','Ar Rawabi'],['الخالدية','Al Khalidiyah'],
            ]],
            ['خميس مشيط', 'Khamis Mushait', 18.3, 42.7333, [
                ['الراقي','Ar Raqi'],['النسيم','An Naseem'],['الموظفين','Al Muwadhafin'],['الجامعيين','Al Jamiyin'],['الشرفية','Ash Sharafiyah'],['المحالة','Al Mahalah'],['الواحة','Al Wahah'],['المنتزه','Al Muntazah'],['التحلية','At Tahliyah'],['الضيافة','Ad Diyafah'],['الريان','Ar Rayyan'],['الربوة','Ar Rabwah'],
            ]],
            ['أبها', 'Abha', 18.2164, 42.5053, [
                ['المنسك','Al Mansak'],['الخشع','Al Khasha'],['المفتاحة','Al Muftaha'],['الربيع','Ar Rabi'],['شمسان','Shamsan'],['البديع','Al Badi'],['السد','As Sadd'],['الوسط','Al Wasat'],['المحالة','Al Mahalah'],['الشرفية','Ash Sharafiyah'],['الورود','Al Wurud'],['ذهبان','Dhahban'],
            ]],
            ['حائل', 'Hail', 27.5114, 41.6903, [
                ['السويفلة','As Suwayfla'],['المنتزة الشرقي','Al Muntaza Ash Sharqi'],['المنتزة الغربي','Al Muntaza Al Gharbi'],['النقرة','An Nuqrah'],['المحطة','Al Mahatta'],['البادية','Al Badiyah'],['الخزامى','Al Khuzama'],['الورود','Al Wurud'],['المصيف','Al Masif'],['الزهور','Az Zuhur'],['السمراء','As Samra'],['صلاح الدين','Salah Ad Din'],
            ]],
            ['نجران', 'Najran', 17.4933, 44.1277, [
                ['الفيصلية','Al Faisaliyah'],['الفهد','Al Fahd'],['المخيم','Al Mukhayyam'],['أبا السعود','Aba As Saud'],['شرورة','Sharurah'],['الضيافة','Ad Diyafah'],['الإسكان','Al Iskan'],['الموفجة','Al Mawfajah'],['بدر','Badr'],['الشرفة','Ash Shurfah'],
            ]],
            ['جازان', 'Jazan', 16.8892, 42.5611, [
                ['الشاطئ','Ash Shati'],['المنتزه','Al Muntazah'],['الروضة','Ar Rawdah'],['الصفا','As Safa'],['المطار','Al Matar'],['النخيل','An Nakheel'],['السويس','As Suwais'],['الريان','Ar Rayyan'],['المحمدية','Al Muhammadiyah'],['البشائر','Al Bashair'],
            ]],
            ['الباحة', 'Al Baha', 20.0, 41.4667, [
                ['الحزام','Al Hizam'],['الخالدية','Al Khalidiyah'],['الجديدة','Al Jadidah'],['الزهور','Az Zuhur'],['الإسكان','Al Iskan'],['بلجرشي','Baljurashi'],['العقيق','Al Aqiq'],['المندق','Al Mandaq'],
            ]],
            ['عرعر', 'Arar', 30.9863, 41.0007, [
                ['المحمدية','Al Muhammadiyah'],['المساعدية','Al Musaadiyah'],['الخالدية','Al Khalidiyah'],['الفيصلية','Al Faisaliyah'],['الجوهرة','Al Jawharah'],['الروضة','Ar Rawdah'],['الورود','Al Wurud'],['الربيع','Ar Rabi'],['النسيم','An Naseem'],
            ]],
            ['سكاكا', 'Sakaka', 29.9697, 40.2064, [
                ['الفيصلية','Al Faisaliyah'],['النهضة','An Nahdah'],['الراشدية','Ar Rashidiyah'],['القادسية','Al Qadisiyah'],['الروضة','Ar Rawdah'],['الصناعية','As Sinaiyah'],['السلام','As Salam'],['الأمير عبدالإله','Al Amir Abdulilah'],
            ]],
            ['الجبيل', 'Jubail', 27.0174, 49.6581, [
                ['الفناتير','Al Fanateer'],['الحويلات','Al Huwailat'],['الدفي','Ad Dafi'],['الدانة','Ad Danah'],['الجبيل الصناعية','Jubail Industrial'],['النزهة','An Nuzhah'],['الفردوس','Al Firdaws'],['الفيحاء','Al Faiha'],['المرجان','Al Murjan'],['المروج','Al Muruj'],
            ]],
            ['ينبع', 'Yanbu', 24.0895, 38.0618, [
                ['ينبع الصناعية','Yanbu Industrial'],['ينبع البحر','Yanbu Al Bahr'],['الحي السكني','Residential'],['الصناعية','As Sinaiyah'],['السويق','As Suwaiq'],['الشرم','Ash Sharm'],['النخيل','An Nakheel'],['السلام','As Salam'],
            ]],
            ['الأحساء', 'Al Ahsa', 25.3831, 49.5853, [
                ['الهفوف','Al Hofuf'],['المبرز','Al Mubarraz'],['العيون','Al Uyun'],['الجفر','Al Jafr'],['الطرف','At Taraf'],['الشعبة','Ash Shubah'],['السلمانية','As Salmaniyah'],['الحليلة','Al Hulailah'],['المنيزلة','Al Munaysla'],['المطيرفي','Al Mutayrifi'],['المنصورة','Al Mansurah'],['العزيزية','Al Aziziyah'],
            ]],
            ['حفر الباطن', 'Hafar Al-Batin', 28.4344, 45.9708, [
                ['السلام','As Salam'],['الربيع','Ar Rabi'],['النايفية','An Naifiyah'],['الصناعية','As Sinaiyah'],['المحمدية','Al Muhammadiyah'],['الخالدية','Al Khalidiyah'],['الفيصلية','Al Faisaliyah'],['العزيزية','Al Aziziyah'],
            ]],
            ['القطيف', 'Qatif', 26.5205, 50.0128, [
                ['القطيف المركز','Qatif Center'],['سيهات','Saihat'],['تاروت','Tarut'],['العوامية','Al Awamiyah'],['صفوى','Safwa'],['الربيعية','Ar Rabiiyah'],['أم الحمام','Umm Al Hamam'],['الجارودية','Al Jarudiyah'],
            ]],
            ['الخرج', 'Al Kharj', 24.1556, 47.3126, [
                ['السلام','As Salam'],['المنشية','Al Manshiyah'],['الريان','Ar Rayyan'],['العزيزية','Al Aziziyah'],['السيح','As Seeh'],['الدلم','Ad Dilam'],['الهياثم','Al Hayatham'],['الخزامى','Al Khuzama'],
            ]],
            ['الزلفي', 'Az Zulfi', 26.2919, 44.7936, [
                ['علقة','Alqah'],['الروضة','Ar Rawdah'],['المحمدية','Al Muhammadiyah'],['الربيعية','Ar Rabiiyah'],['سمنان','Samnan'],
            ]],
            ['المجمعة', 'Al Majmaah', 25.9236, 45.3443, [
                ['الجامعة','Al Jamiah'],['الفيصلية','Al Faisaliyah'],['الشعلان','Ash Shaalan'],['حوطة سدير','Hawtat Sudayr'],
            ]],
            ['الدوادمي', 'Ad Dawadmi', 24.5073, 44.3928, [
                ['القويعية','Al Quwaiyah'],['السلام','As Salam'],['نفي','Nifi'],['ساجر','Sajir'],
            ]],
            ['وادي الدواسر', 'Wadi Ad-Dawasir', 20.4917, 44.8003, [
                ['الخماسين','Al Khamasin'],['النويعمة','An Nuwaymah'],['الفاو','Al Faw'],
            ]],
            ['بيشة', 'Bisha', 19.9888, 42.5983, [
                ['المخطط','Al Mukhtat'],['الوسط','Al Wasat'],['الحميمة','Al Humaimah'],['الجعبة','Al Jabah'],['النقيع','An Naqi'],
            ]],
            ['الرس', 'Ar Rass', 25.867, 43.5, [
                ['الشنانة','Ash Shananah'],['الفيصلية','Al Faisaliyah'],['المحمدية','Al Muhammadiyah'],['الخالدية','Al Khalidiyah'],
            ]],
            ['رابغ', 'Rabigh', 22.8, 39.0, [
                ['المركز','Al Markaz'],['الساحل','As Sahil'],['المستودعات','Al Mustawdaat'],['ثول','Thuwal'],
            ]],
            ['القنفذة', 'Al Qunfudhah', 19.1267, 41.0848, [
                ['المركز','Al Markaz'],['حلى','Hali'],['القوز','Al Qawz'],['دوقة','Dawqah'],
            ]],
            ['محايل عسير', 'Muhayil Asir', 18.5353, 42.0453, [
                ['المركز','Al Markaz'],['بارق','Bariq'],['المجاردة','Al Majardah'],
            ]],
            ['صبيا', 'Sabya', 17.15, 42.625, [
                ['المركز','Al Markaz'],['الدرب','Ad Darb'],['أحد المسارحة','Ahad Al Masarihah'],
            ]],
            ['الليث', 'Al Lith', 20.15, 40.2667, [
                ['المركز','Al Markaz'],['أضم','Adham'],['الشواق','Ash Shawaq'],
            ]],
            ['شقراء', 'Shaqra', 25.25, 45.25, [
                ['المركز','Al Markaz'],['مرات','Marat'],['القصب','Al Qasab'],
            ]],
            ['عفيف', 'Afif', 23.9064, 42.9194, [
                ['المركز','Al Markaz'],['الروضة','Ar Rawdah'],
            ]],
            ['ضباء', 'Duba', 27.3475, 35.6886, [
                ['المركز','Al Markaz'],['الوجه','Al Wajh'],['حقل','Haql'],['أملج','Umluj'],
            ]],
            ['طريف', 'Turaif', 31.6714, 38.6553, [
                ['المركز','Al Markaz'],['الروضة','Ar Rawdah'],['النسيم','An Naseem'],
            ]],
            ['رفحاء', 'Rafha', 29.6203, 43.4942, [
                ['المركز','Al Markaz'],['الروضة','Ar Rawdah'],['الفيصلية','Al Faisaliyah'],
            ]],
        ];

        foreach ($data as [$nameAr, $nameEn, $lat, $lng, $dists]) {
            if (City::where('name_ar', $nameAr)->exists()) continue;

            $city = City::create([
                'name_ar'   => $nameAr,
                'name_en'   => $nameEn,
                'latitude'  => $lat,
                'longitude' => $lng,
                'status'    => 'active',
            ]);

            foreach ($dists as [$dAr, $dEn]) {
                District::create([
                    'city_id' => $city->id,
                    'name_ar' => $dAr,
                    'name_en' => $dEn,
                    'status'  => 'active',
                ]);
            }
        }
    }
}
