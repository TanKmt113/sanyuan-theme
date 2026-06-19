# SANYUAN Cable — WordPress theme (Roots Sage)

Bản sao **giống y hệt** giao diện của `www.sanyuancable.com.cn`, đóng gói thành một
theme WordPress dựa trên [Roots Sage 11](https://roots.io/sage/).

> Nguyên tắc: **không thêm/sửa CSS hay JS nào**. Toàn bộ HTML/CSS/JS gốc được giữ
> nguyên xi (byte-identical). Thứ duy nhất bị đổi là *tiền tố đường dẫn asset*
> (ảnh, css, js) để trỏ về thư mục `public/` của theme.

---

## 1. Yêu cầu môi trường

- PHP **8.3+** (bật extension `fileinfo`, `mbstring`)
- WordPress **6.6+**
- Composer 2.2+ và Node.js 18+ (chỉ cần khi muốn build lại Sage; xem mục 4)

## 2. Cài đặt

1. Copy cả thư mục `sanyuan-theme/` vào `wp-content/themes/` của site WordPress.
2. Trong thư mục theme, cài dependency PHP (đã kèm sẵn `vendor/`, nhưng nên chạy lại
   trên server đích để chắc chắn):
   ```bash
   composer install
   ```
3. Vào **wp-admin → Appearance → Themes**, kích hoạt **“SANYUAN Cable (Sage)”**.

## 3. Gán các trang

Mỗi trang gốc đã thành một **Page Template** chọn được trong wp-admin.

### Trang chủ
1. Tạo một Page mới (vd tên “Home”).
2. **Settings → Reading → Your homepage displays → A static page → Homepage = Home**.
3. Trang chủ tự dùng template `front-page.blade.php` (= `index.html` gốc). Không cần
   gán template thủ công.

### Các trang còn lại
Tạo một Page cho mỗi trang, rồi ở **Page Attributes → Template** chọn template tương ứng:

| Template (trong wp-admin)                | Nội dung gốc                          |
|------------------------------------------|---------------------------------------|
| SANYUAN — About                          | `about.html`                          |
| SANYUAN — Product                        | `product.html`                        |
| SANYUAN — News                           | `news.html`                           |
| SANYUAN — Support                        | `Support.html`                        |
| SANYUAN — Contact                        | `concact.html`                        |
| SANYUAN — ESG                            | `ESG.html`                            |
| SANYUAN — Cable Compliance               | `CableCompliance.html`                |
| SANYUAN — Cable Lab Overview             | `CableLabOverview.html`               |
| SANYUAN — Cable Testing & Inspection     | `CableTestingInspection.html`         |
| SANYUAN — Product Detail (sample)        | `product_Details/100.html`            |
| SANYUAN — News Detail (sample)           | `NewDetails/2064603554492653568.html` |

> Nội dung Page (khung soạn thảo) có thể để trống — template tự render toàn bộ HTML.

## 4. Build assets của Sage (tùy chọn)

Các trang đã chuyển đổi **không** dùng `app.css`/`app.js` của Sage nên **không bắt buộc**
phải build. Chỉ chạy `npm install && npm run build` nếu bạn muốn dùng thêm các view
mặc định của Sage (`page`, `single`, `404`…) vốn nạp asset qua Vite.

---

## Kiến trúc — cách giữ “giống y hệt”

```
sanyuan-theme/
├── index.php                       # điểm render duy nhất: render view rồi thay
│                                   # __ASSET__  ->  URL thật của public/
├── public/                         # asset gốc copy nguyên xi
│   ├── css/  npublic/  upload/      #   (css, js, font…)
│   ├── assets_img/                 #   792 ảnh
│   └── favicon.ico
└── resources/views/
    ├── front-page.blade.php        # loader 1 dòng cho trang chủ
    ├── template-*.blade.php        # loader 1 dòng + header "Template Name"
    └── static/*.html               # HTML gốc, chỉ rewrite tiền tố asset
```

**Luồng render:**
1. WordPress chọn template (front-page / template-*) → Sage map sang Blade view.
2. Blade view chỉ là 1 dòng: `{!! file_get_contents('…/static/<slug>.html') !!}`.
   → Markup **không** đi qua bộ biên dịch Blade, nên JS inline, `{{ }}`, `@`… của
   trang gốc không bị đụng tới (và tránh giới hạn PCRE với trang >1MB).
3. `index.php` thay placeholder `__ASSET__` bằng URL `wp-content/themes/sanyuan-theme/public`.

**Vì sao byte-identical:** mỗi `static/<slug>.html` bằng đúng file HTML gốc, chỉ
khác các tiền tố:
- `../assets_img/`, `../../assets_img/`  →  `__ASSET__/assets_img/`
- `css/`, `npublic/`, `upload/` (mọi độ sâu `../`)  →  `__ASSET__/…`
- `favicon.ico`  →  `__ASSET__/favicon.ico`

Đã kiểm chứng bằng `test_render.php`: render qua Blade rồi diff với bản gốc →
**12/12 trang IDENTICAL**.

---

## Lưu ý / giới hạn

- **Liên kết điều hướng nội bộ** (menu, nút) vẫn trỏ tới tên file `.html` gốc
  (vd `about.html`, `../product_list/123.html`). Đây là markup gốc giữ nguyên.
  Để menu bấm sang đúng Page WordPress, cần map các link này sang permalink —
  có thể làm sau (chưa nằm trong phạm vi “giao diện giống y hệt”).
- Mới chuyển **các trang chính + 1 product detail + 1 news detail** làm mẫu. Mọi
  trang `product_Details/*`, `product_list/*` khác có thể chuyển thêm bằng script
  `convert.sh` ở thư mục gốc dự án:
  ```bash
  ./convert.sh <src.html> <slug> resources/views/template-<slug>.blade.php "Tên hiển thị"
  ```
- Các trang `CableComplianceDetails/*` trong bản mirror đều bị WAF chặn (403) nên
  không có nội dung thật để chuyển — đã thay bằng 1 trang News detail làm mẫu.
- Asset gộp (vd `ceccbootstrap.min.css%2cglobalb1cb.css`) phụ thuộc web server tự
  decode `%2c` → `,` để khớp tên file trên đĩa. Apache/Nginx mặc định làm được.
