<?php

namespace App\Console\Commands;

use App\Models\Student;
use Illuminate\Console\Command;

class MapStudentsProvince extends Command
{
    protected $signature = 'provinces:map-students {--dry-run : Chỉ in báo cáo, không ghi DB}';

    protected $description = 'Ánh xạ permanent_address của sinh viên sang province_code mới (34 tỉnh sau sáp nhập 2025)';

    /**
     * Bản đồ: code tỉnh mới => các từ khóa (tên tỉnh/thành cũ & mới, đã bỏ dấu).
     * Một địa chỉ cũ ghi tên tỉnh cũ sẽ được gom về đơn vị mới tương ứng.
     * Các từ khóa nhiều từ giúp tránh nhận nhầm.
     */
    private array $aliasMap = [
        '79' => ['ho chi minh', 'tp hcm', 'tp.hcm', 'tphcm', 'hcm', 'sai gon', 'saigon', 'binh duong', 'ba ria', 'vung tau', 'brvt'],
        '01' => ['ha noi', 'hanoi'],
        '31' => ['hai phong', 'hai duong'],
        '46' => ['thua thien hue', 'thua thien', 'hue'],
        '48' => ['da nang', 'danang', 'quang nam', 'tam ky', 'hoi an'],
        '92' => ['can tho', 'soc trang', 'hau giang'],
        '04' => ['cao bang'],
        '08' => ['tuyen quang', 'ha giang'],
        '10' => ['lao cai', 'yen bai'],
        '11' => ['dien bien'],
        '12' => ['lai chau'],
        '14' => ['son la'],
        '19' => ['thai nguyen', 'bac kan', 'bac can'],
        '20' => ['lang son'],
        '22' => ['quang ninh', 'ha long'],
        '25' => ['phu tho', 'vinh phuc', 'hoa binh', 'viet tri'],
        '27' => ['bac ninh', 'bac giang'],
        '33' => ['hung yen', 'thai binh'],
        '37' => ['ninh binh', 'ha nam', 'nam dinh'],
        '38' => ['thanh hoa'],
        '40' => ['nghe an'],
        '42' => ['ha tinh'],
        '45' => ['quang tri', 'quang binh', 'dong hoi'],
        '51' => ['quang ngai', 'kon tum'],
        '56' => ['khanh hoa', 'nha trang', 'ninh thuan', 'phan rang'],
        '64' => ['gia lai', 'pleiku', 'binh dinh', 'quy nhon'],
        '66' => ['dak lak', 'dac lac', 'buon ma thuot', 'phu yen', 'tuy hoa'],
        '68' => ['lam dong', 'da lat', 'dalat', 'dak nong', 'dac nong', 'binh thuan', 'phan thiet'],
        '72' => ['tay ninh', 'long an', 'tan an'],
        '75' => ['dong nai', 'bien hoa', 'binh phuoc', 'dong xoai'],
        '86' => ['vinh long', 'ben tre', 'tra vinh'],
        '87' => ['dong thap', 'cao lanh', 'tien giang', 'my tho'],
        '89' => ['an giang', 'long xuyen', 'kien giang', 'rach gia', 'phu quoc'],
        '96' => ['ca mau', 'bac lieu'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $students = Student::query()
            ->whereNull('province_code')
            ->whereNotNull('permanent_address')
            ->get(['id', 'permanent_address', 'province_code']);

        $total = $students->count();
        $mapped = 0;
        $stillNull = 0;

        foreach ($students as $student) {
            $code = $this->resolveCode($student->permanent_address);

            if ($code === null) {
                $stillNull++;
                continue;
            }

            if (! $dryRun) {
                $student->province_code = $code;
                $student->save();
            }

            $mapped++;
        }

        $this->newLine();
        $this->info('=== BÁO CÁO ÁNH XẠ TỈNH/THÀNH ===');
        $this->line('Chế độ:           ' . ($dryRun ? 'DRY-RUN (không ghi DB)' : 'GHI DB'));
        $this->line('Tổng cần xử lý:   ' . $total . ' (province_code = NULL, có permanent_address)');
        $this->line('Ánh xạ được:      ' . $mapped);
        $this->line('Còn NULL:         ' . $stillNull . ' (chờ admin điền tay)');
        $this->newLine();

        return self::SUCCESS;
    }

    private function resolveCode(?string $address): ?string
    {
        if (! $address) {
            return null;
        }

        $haystack = $this->normalize($address);

        foreach ($this->aliasMap as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Đưa chuỗi về chữ thường, bỏ dấu tiếng Việt và gộp khoảng trắng
     * để so khớp từ khóa ổn định.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        $map = [
            'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
            'ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
            'ỳ','ý','ỵ','ỷ','ỹ','đ',
        ];
        $replace = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y','d',
        ];

        $value = str_replace($map, $replace, $value);

        // Bỏ dấu chấm trong "tp.hcm" -> "tphcm" cũng được giữ lại nhờ alias riêng;
        // chuẩn hóa khoảng trắng thừa.
        return preg_replace('/\s+/', ' ', $value);
    }
}
