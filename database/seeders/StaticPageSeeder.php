<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StaticPageSeeder extends Seeder
{
    /**
     * Chỉ TẠO MỚI nếu slug chưa tồn tại — KHÔNG BAO GIỜ ghi đè bản ghi đã có sẵn, dù chạy lại
     * seeder bao nhiêu lần. Trước đây dùng updateOrInsert() nên mỗi lần chạy lại sẽ ghi đè mất
     * nội dung admin đã chỉnh sửa thật (báo cáo 02/08: mất ảnh trang Giới thiệu do seed lại).
     */
    private function seedIfMissing(string $slug, array $data): void
    {
        if (DB::table('static_pages')->where('slug', $slug)->exists()) {
            return;
        }

        DB::table('static_pages')->insert(array_merge(['slug' => $slug], $data, [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));
    }

    public function run(): void
    {
        $this->seedIfMissing('gioithieu', [
            'title' => 'Giới thiệu Ký túc xá STU',

            'summary' => 'Hệ thống ký túc xá Trường Đại học Công nghệ Sài Gòn (STU) đem đến chỗ ở an toàn, '
                       . 'đầy đủ tiện nghi ngay trong trường giúp cho sinh viên an tâm ngay từ ngày đầu nhập học.',

            'stats' => json_encode([
                ['label' => 'Sức chứa',        'value' => '600+',      'unit' => 'chỗ ở'],
                ['label' => 'Học phí lưu trú', 'value' => '350.000đ',  'unit' => '/tháng'],
                ['label' => 'Bảo vệ',          'value' => '24/7',      'unit' => ''],
                ['label' => 'Thành lập',        'value' => '1997',      'unit' => ''],
            ]),

            'content' => <<<HTML
<p>Khi bạn lựa chọn Trường Đại học Công nghệ Sài Gòn (STU) để theo đuổi ước mơ học tập, một trong những điều quan trọng không thể bỏ qua chính là môi trường sống và học tập. STU tự hào mang đến cho sinh viên ký túc xá ngay trong khuôn viên trường, một giải pháp tiện ích giúp sinh viên an tâm học tập và sinh hoạt từ ngày đầu nhập học. Với cơ sở vật chất hiện đại, môi trường an toàn và đầy đủ tiện nghi, ký túc xá STU không chỉ mang lại sự tiện lợi mà còn giúp sinh viên tiết kiệm chi phí và dễ dàng hòa nhập với nhịp sống sinh viên.</p>

<h3>1. Ký túc xá STU: tiện nghi, an toàn và gần gũi</h3>
<p>Ký túc xá tại STU được xây dựng với mục tiêu mang đến không gian sinh hoạt thoải mái và tiện nghi cho sinh viên. Nằm ngay trong khuôn viên trường, ký túc xá tạo điều kiện cho sinh viên dễ dàng di chuyển đến các phòng học, thư viện, khu thể thao và các cơ sở tiện ích khác của trường. Điều này giúp sinh viên tiết kiệm thời gian, dễ dàng tham gia các hoạt động học tập và ngoại khóa mà không phải lo lắng về việc di chuyển xa.</p>
<p>Mỗi phòng trong ký túc xá đều được trang bị đầy đủ nội thất như giường, tủ, bàn học, điều hòa và các tiện nghi cơ bản, đảm bảo sự thoải mái trong suốt quá trình học tập. Ngoài ra, hệ thống wifi miễn phí và khu vực sinh hoạt chung giúp sinh viên dễ dàng kết nối và trao đổi kiến thức. Ký túc xá cũng được bảo vệ 24/7, đảm bảo an toàn tuyệt đối cho sinh viên.</p>

<h3>2. Môi trường sống xanh, khỏe mạnh, phát triển toàn diện</h3>
<p>Tại STU, môi trường học tập không chỉ gói gọn trong lớp học mà còn bao gồm không gian sinh hoạt. Ký túc xá STU được xây dựng với không gian mở, sân vườn, và khu vực thể thao, giúp sinh viên có thể thư giãn, giải trí và rèn luyện sức khỏe sau những giờ học căng thẳng. STU khuyến khích sinh viên tham gia các hoạt động thể thao và ngoại khóa để phát triển toàn diện cả về thể chất lẫn tinh thần.</p>
<p>Ngoài ra, với chế độ ăn uống lành mạnh, ký túc xá còn có căng tin phục vụ các món ăn dinh dưỡng, giúp sinh viên duy trì sức khỏe và tăng cường năng lượng cho những giờ học hiệu quả. Môi trường sống tại STU luôn đảm bảo sự hòa hợp và thân thiện, giúp sinh viên dễ dàng làm quen và kết bạn mới.</p>

<h3>3. Chỗ ở tiện lợi, tiết kiệm chi phí</h3>
<p>Ký túc xá STU không chỉ tiện lợi mà còn tiết kiệm chi phí cho sinh viên. Với mức phí hợp lý và nhiều tiện ích đi kèm, sinh viên sẽ không phải lo lắng về vấn đề nơi ở khi học tập xa nhà. Chương trình tuyển sinh STU còn hỗ trợ sinh viên có nhu cầu chỗ ở từ ngày đầu nhập học, giúp các bạn dễ dàng ổn định cuộc sống và tập trung vào việc học.</p>
<p>Ký túc xá tại STU chính là một phần trong hệ sinh thái hỗ trợ sinh viên, giúp các bạn có một môi trường học tập và sinh hoạt đầy đủ, an toàn và gần gũi.</p>
HTML,

            'images' => json_encode([
                '/images/ktx/banner.jpg',
                '/images/ktx/phong-o.jpg',
                '/images/ktx/can-tin.jpg',
                '/images/ktx/san-the-thao.jpg',
                '/images/ktx/phong-tu-hoc.jpg',
            ]),

            'is_active' => true,
        ]);

        $this->seedIfMissing('quy-dinh-dang-ky', [
            'title'   => 'Quy định về đăng ký và thanh toán',
            'summary' => null,
            'content' => null,
            'items'   => json_encode([
                [
                    'icon'        => 'BadgeCheck',
                    'title'       => 'Điều kiện đăng ký',
                    'description' => 'Sinh viên STU đang theo học, có nhu cầu lưu trú và đáp ứng quy định xét duyệt của ký túc xá.',
                    'detail_path' => '/dieu-kien-noi-tru',
                ],
                [
                    'icon'        => 'FileText',
                    'title'       => 'Hồ sơ cần chuẩn bị',
                    'description' => 'Thông tin cá nhân, minh chứng ưu tiên nếu có và các giấy tờ cần thiết theo yêu cầu từng đợt.',
                    'detail_path' => '/ho-so-can-chuan-bi',
                ],
                [
                    'icon'        => 'ClipboardCheck',
                    'title'       => 'Quy trình xét duyệt',
                    'description' => 'Hồ sơ được kiểm tra, xếp thứ tự ưu tiên và cập nhật kết quả trực tiếp trên tài khoản sinh viên.',
                    'detail_path' => '/quy-trinh-xet-duyet',
                ],
                [
                    'icon'        => 'CreditCard',
                    'title'       => 'Quy định thanh toán',
                    'description' => 'Sinh viên thanh toán phí nội trú theo hóa đơn được phát hành sau khi được phân phòng hoặc chọn giường.',
                    'detail_path' => '/quy-dinh-thanh-toan',
                ],
                [
                    'icon'        => 'ShieldCheck',
                    'title'       => 'Nội quy KTX',
                    'description' => 'Quy định về giờ giấc, an toàn, vệ sinh chung và xử lý vi phạm trong quá trình lưu trú.',
                    'detail_path' => '/noi-quy-ktx',
                ],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        $this->seedIfMissing('co-so-vat-chat', [
            'title'   => 'Cơ sở vật chất',
            'summary' => null,
            'content' => null,
            'items'   => json_encode([
                ['icon' => 'Home',      'title' => 'Phòng ở',        'description' => 'Không gian lưu trú được quản lý theo phòng, tầng và khu.',                 'detail_path' => null],
                ['icon' => 'Utensils',  'title' => 'Căn tin',        'description' => 'Hỗ trợ bữa ăn và nhu cầu sinh hoạt hằng ngày.',                              'detail_path' => null],
                ['icon' => 'Car',       'title' => 'Bãi xe',         'description' => 'Khu vực gửi xe thuận tiện cho sinh viên nội trú.',                          'detail_path' => null],
                ['icon' => 'BookOpen',  'title' => 'Khu tự học',     'description' => 'Không gian yên tĩnh phục vụ học tập và làm việc nhóm.',                     'detail_path' => null],
                ['icon' => 'Users',     'title' => 'Khu sinh hoạt',  'description' => 'Khu vực sinh hoạt chung dành cho các hoạt động tập thể.',                   'detail_path' => null],
                ['icon' => 'Camera',    'title' => 'Camera an ninh', 'description' => 'Theo dõi khu vực chung, hỗ trợ đảm bảo an toàn lưu trú.',                   'detail_path' => null],
                ['icon' => 'Flame',     'title' => 'PCCC',           'description' => 'Trang bị thiết bị và quy trình kiểm tra an toàn định kỳ.',                  'detail_path' => null],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        // ---- 5 trang chi tiết (nội dung bên trong các thẻ ở "quy-dinh-dang-ky") ----

        $this->seedIfMissing('ct-dieu-kien-dang-ky', [
            'title'   => 'Điều kiện đăng ký nội trú STU',
            'summary' => 'Sinh viên cần đáp ứng các điều kiện dưới đây trước khi nộp hồ sơ.',
            'content' => null,
            'items'   => null,
            'sections' => json_encode([
                'hero_icon' => 'ShieldCheck',
                'groups' => [
                    ['icon' => 'BadgeCheck',   'title' => 'Điều kiện học tập', 'description' => null, 'items' => ['Sinh viên chính quy', 'Không phải năm cuối']],
                    ['icon' => 'ShieldCheck',  'title' => 'Điều kiện kỷ luật', 'description' => null, 'items' => ['Không nằm trong danh sách blacklist', 'Không vi phạm nghiêm trọng trong đợt ở cũ']],
                    ['icon' => 'CreditCard',   'title' => 'Điều kiện tài chính và hệ thống', 'description' => null, 'items' => ['Không nợ hóa đơn', 'Đợt đăng ký đang mở', 'Không có đơn đang xử lý']],
                ],
                'steps' => [],
                'highlights_title' => 'Các trường hợp không đủ điều kiện đăng ký',
                'highlights' => [
                    ['icon' => 'Home',      'label' => 'Đang nợ tiền phòng'],
                    ['icon' => 'AlarmClock','label' => 'Đang nợ tiền điện'],
                    ['icon' => 'ShieldCheck', 'label' => 'Bị đưa vào danh sách cấm đăng ký'],
                    ['icon' => 'FileText',  'label' => 'Đã có hồ sơ đang xử lý'],
                    ['icon' => 'Users',     'label' => 'Không thuộc đối tượng được đăng ký'],
                ],
                'notes_title' => null,
                'notes' => [],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        $this->seedIfMissing('ct-ho-so-can-chuan-bi', [
            'title'   => 'Hồ sơ cần chuẩn bị khi đăng ký nội trú',
            'summary' => 'Sinh viên cần chuẩn bị đầy đủ thông tin và giấy tờ trước khi nộp hồ sơ đăng ký ký túc xá để quá trình xét duyệt diễn ra thuận lợi.',
            'content' => null,
            'items'   => null,
            'sections' => json_encode([
                'hero_icon' => 'FileText',
                'groups' => [
                    ['icon' => 'Users',    'title' => 'Thông tin cá nhân', 'description' => null, 'items' => ['Họ và tên', 'MSSV', 'Ngày sinh', 'Giới tính', 'Số điện thoại', 'Email sinh viên']],
                    ['icon' => 'BadgeCheck','title' => 'CCCD/CMND', 'description' => null, 'items' => ['CCCD còn hiệu lực', 'Ảnh mặt trước', 'Ảnh mặt sau', 'Hình ảnh rõ nét']],
                    ['icon' => 'Camera',   'title' => 'Ảnh chân dung', 'description' => null, 'items' => ['Ảnh thẻ hoặc ảnh chân dung', 'Khuôn mặt rõ ràng', 'Không bị mờ']],
                    ['icon' => 'ClipboardCheck', 'title' => 'Giấy tờ ưu tiên (nếu có)', 'description' => null, 'items' => ['Hộ nghèo', 'Cận nghèo', 'Gia đình chính sách', 'Hoàn cảnh khó khăn', 'Các minh chứng ưu tiên khác']],
                ],
                'steps' => [
                    ['icon' => 'Users',    'title' => 'Chuẩn bị thông tin cá nhân'],
                    ['icon' => 'BadgeCheck','title' => 'Chuẩn bị CCCD và ảnh'],
                    ['icon' => 'ClipboardCheck', 'title' => 'Bổ sung giấy tờ ưu tiên (nếu có)'],
                    ['icon' => 'FileText', 'title' => 'Nộp hồ sơ trực tuyến'],
                ],
                'highlights_title' => null,
                'highlights' => [],
                'notes_title' => 'Lưu ý quan trọng',
                'notes' => [
                    'Thông tin khai báo phải chính xác.',
                    'Giấy tờ tải lên phải rõ ràng và đầy đủ.',
                    'Hồ sơ không hợp lệ có thể bị từ chối xét duyệt.',
                    'Sinh viên chịu trách nhiệm về tính chính xác của thông tin cung cấp.',
                ],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        $this->seedIfMissing('ct-quy-trinh-xet-duyet', [
            'title'   => 'Quy trình xét duyệt nội trú STU',
            'summary' => 'Từ lúc nộp hồ sơ đến khi nhận phòng, toàn bộ quy trình được thực hiện trực tuyến và cập nhật minh bạch trên hệ thống.',
            'content' => null,
            'items'   => null,
            'sections' => json_encode([
                'hero_icon' => 'ClipboardCheck',
                'groups' => [
                    ['icon' => 'FileText',      'title' => 'Đăng ký hồ sơ', 'description' => 'Sinh viên đăng nhập hệ thống, điền đầy đủ thông tin cá nhân và tải lên các giấy tờ yêu cầu trong thời gian đợt đăng ký đang mở.', 'items' => ['Điền thông tin cá nhân chính xác', 'Tải ảnh CCCD mặt trước và sau', 'Tải ảnh chân dung rõ nét', 'Bổ sung minh chứng ưu tiên nếu có']],
                    ['icon' => 'ClipboardCheck','title' => 'Xét duyệt hồ sơ', 'description' => 'Ban quản lý ký túc xá kiểm tra điều kiện, tính hợp lệ của hồ sơ và xếp thứ tự ưu tiên theo quy định.', 'items' => ['Kiểm tra điều kiện đăng ký', 'Xác minh tính hợp lệ của giấy tờ', 'Xếp hạng ưu tiên theo đối tượng', 'Thông báo kết quả qua tài khoản sinh viên']],
                    ['icon' => 'Building2',     'title' => 'Phân phòng', 'description' => 'Sau khi hồ sơ được duyệt, hệ thống tự động sắp xếp phòng phù hợp theo giới tính, khu và tầng theo quy định.', 'items' => ['Phân theo giới tính', 'Ưu tiên theo đối tượng chính sách', 'Thông báo phòng được phân qua hệ thống']],
                    ['icon' => 'BedDouble',     'title' => 'Chọn giường', 'description' => 'Sinh viên được phân phòng sẽ đăng nhập vào hệ thống để chọn giường trống phù hợp trong phòng đã được chỉ định.', 'items' => ['Chọn trong thời gian quy định', 'Xem sơ đồ giường trong phòng', 'Xác nhận lựa chọn trước khi thanh toán']],
                    ['icon' => 'CreditCard',    'title' => 'Thanh toán', 'description' => 'Sinh viên thanh toán phí nội trú theo hóa đơn được phát hành sau khi xác nhận giường. Thanh toán đúng hạn để hoàn tất thủ tục.', 'items' => ['Xem hóa đơn chi tiết trên hệ thống', 'Thanh toán trực tuyến qua VNPay', 'Lưu biên lai để đối chiếu khi cần']],
                    ['icon' => 'Home',          'title' => 'Nhận phòng', 'description' => 'Sau khi hoàn tất thanh toán, sinh viên nhận thông tin phòng và giường chính thức, thực hiện thủ tục nhận phòng theo hướng dẫn.', 'items' => ['Nhận thông tin phòng qua hệ thống', 'Mang theo CCCD và biên lai thanh toán', 'Ký biên bản bàn giao tài sản phòng']],
                ],
                'steps' => [],
                'highlights_title' => null,
                'highlights' => [],
                'notes_title' => null,
                'notes' => [],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        $this->seedIfMissing('ct-quy-dinh-thanh-toan', [
            'title'   => 'Quy định về đăng ký và thanh toán',
            'summary' => 'Các mốc thời gian và quy định quan trọng sinh viên cần nắm rõ trong suốt quá trình đăng ký, nhận phòng và lưu trú tại ký túc xá.',
            'content' => null,
            'items'   => null,
            'sections' => json_encode([
                'hero_icon' => 'AlarmClock',
                'groups' => [
                    ['icon' => 'FileText',   'title' => 'Đợt đăng ký nội trú', 'description' => 'Đợt đăng ký là khoảng thời gian nhà trường mở cho sinh viên nộp hồ sơ xin ở ký túc xá, mỗi đợt có thời gian nhận đơn và thời gian lưu trú áp dụng riêng.', 'items' => ['Tân sinh viên giữ chỗ bằng mã hồ sơ trúng tuyển, sinh viên năm nhất đã có MSSV nộp qua đợt chính', 'Sinh viên năm 2 trở lên đăng ký qua đợt quanh năm, hoặc xin gia hạn nếu đang ở', 'Mỗi sinh viên chỉ được có 1 hồ sơ đang xử lý tại 1 thời điểm, không nộp trùng nhiều đợt']],
                    ['icon' => 'BedDouble',  'title' => 'Thời hạn chọn giường', 'description' => 'Sau khi hồ sơ được duyệt và phân phòng, sinh viên có một khoảng thời gian nhất định để tự chọn giường cụ thể trong phòng đã phân — thời hạn chính xác được thông báo trực tiếp trên tài khoản.', 'items' => ['Chọn giường ngay trong thời hạn được thông báo trên hệ thống', 'Quá hạn mà chưa chọn giường, hồ sơ tự động bị hủy', 'Giường sẽ được giải phóng cho sinh viên khác nếu bị hủy']],
                    ['icon' => 'CreditCard', 'title' => 'Thời hạn thanh toán hóa đơn tháng đầu', 'description' => 'Sau khi chọn giường, hệ thống tự tạo hóa đơn tháng đầu, sinh viên có một khoảng thời gian nhất định để hoàn tất thanh toán qua VNPay.', 'items' => ['Thanh toán trực tuyến qua VNPay ngay trên hệ thống', 'Quá hạn chưa thanh toán, chỗ ở tự động bị hủy tương tự trường hợp trễ chọn giường', 'Chỉ khi thanh toán xong, chỗ ở mới được xác nhận chính thức']],
                    ['icon' => 'CreditCard', 'title' => 'Hóa đơn định kỳ trong quá trình ở', 'description' => 'Trong suốt thời gian lưu trú, hóa đơn tiền phòng được thu theo quý (gộp các tháng còn lại trong quý), thanh toán trực tuyến ngay trên hệ thống.', 'items' => ['Xem và thanh toán hóa đơn qua VNPay, theo dõi lịch sử thanh toán trên tài khoản', 'Quá hạn đóng hóa đơn, hệ thống tự động nhắc nợ theo nhiều mức tăng dần', 'Nợ kéo dài quá lâu có thể bị buộc thôi ở và cấm đăng ký lại']],
                ],
                'steps' => [],
                'highlights_title' => null,
                'highlights' => [],
                'notes_title' => null,
                'notes' => [],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);

        $this->seedIfMissing('ct-noi-quy-ktx', [
            'title'   => 'Nội quy Ký túc xá STU',
            'summary' => 'Sinh viên nội trú cần tuân thủ đầy đủ các quy định dưới đây để đảm bảo an toàn, trật tự và nếp sống văn minh trong ký túc xá.',
            'content' => null,
            'items'   => null,
            'sections' => json_encode([
                'hero_icon' => 'ShieldCheck',
                'groups' => [
                    ['icon' => 'AlarmClock', 'title' => 'Giờ giấc & ra vào', 'description' => null, 'items' => ['Có mặt tại phòng trước 22:00 hàng ngày', 'Không tự ý dẫn người lạ, khách qua đêm vào ký túc xá khi chưa đăng ký với ban quản lý', 'Ra vào đúng cổng, tuân thủ hướng dẫn của bảo vệ']],
                    ['icon' => 'Flame',      'title' => 'Vệ sinh & an toàn chung', 'description' => null, 'items' => ['Không nấu ăn trong phòng', 'Giữ gìn vệ sinh chung, không xả rác bừa bãi', 'Tuân thủ quy định phòng cháy chữa cháy, không tự ý câu móc điện']],
                    ['icon' => 'ShieldCheck','title' => 'Vật dụng & chất cấm', 'description' => null, 'items' => ['Không tàng trữ, sử dụng chất gây nổ, hóa chất nguy hiểm', 'Không tàng trữ, sử dụng chất cấm, ma túy', 'Không sử dụng rượu bia gây mất trật tự trong ký túc xá']],
                ],
                'steps' => [],
                'highlights_title' => 'Xử lý vi phạm',
                'highlights' => [
                    ['icon' => 'ShieldCheck', 'label' => 'Vi phạm được ghi nhận và lưu lại trong hồ sơ lưu trú'],
                    ['icon' => 'AlarmClock',  'label' => 'Vi phạm tích lũy có thể ảnh hưởng việc xét duyệt gia hạn lưu trú'],
                    ['icon' => 'Flame',       'label' => 'Vi phạm nghiêm trọng có thể bị buộc thôi ở ngay lập tức'],
                    ['icon' => 'ShieldCheck', 'label' => 'Buộc thôi ở do vi phạm sẽ bị đưa vào danh sách cấm đăng ký lại'],
                ],
                'notes_title' => null,
                'notes' => [],
            ]),
            'stats'     => null,
            'images'    => json_encode([]),
            'is_active' => true,
        ]);
    }
}
