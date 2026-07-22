<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StaticPageSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('static_pages')->updateOrInsert(
            ['slug' => 'gioithieu'],
            [
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

                'is_active'  => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
}
