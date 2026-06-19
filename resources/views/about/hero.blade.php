{{-- Clean, ACF-driven Hero section for the About page. --}}
@php
  $f = fn ($k) => (function_exists('get_field') && ($v = get_field($k)) !== '' && $v !== null) ? $v : '';
  $title     = $f('about_hero_title');
  $highlight = $f('about_hero_highlight');
  $titleEnd  = $f('about_hero_title_end');
  $desc      = $f('about_hero_desc');
  $img       = $f('about_hero_image');
@endphp
<style>
  .sy-hero{position:relative;max-width:1320px;margin:0 auto;padding:80px 24px;display:flex;align-items:center;gap:60px;flex-wrap:wrap;overflow:hidden}
  .sy-hero__media{position:relative;flex:0 0 46%;max-width:600px}
  .sy-hero__media::before{content:"";position:absolute;left:-26px;top:-26px;width:55%;height:55%;
       background:#d40b1c;border-radius:50%;z-index:0}
  .sy-hero__photo{position:relative;z-index:1;width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:50%;display:block}
  .sy-hero__body{flex:1 1 360px;position:relative;z-index:1}
  .sy-hero__watermark{position:absolute;right:0;top:-70px;font-size:150px;font-weight:800;color:#f4f4f4;
       z-index:0;line-height:1;pointer-events:none;user-select:none}
  .sy-hero h1{position:relative;z-index:1;margin:0;font-size:46px;line-height:1.25;color:#1d1d1d;font-weight:800}
  .sy-hero h1 .hl{color:#d40b1c}
  .sy-hero__desc{position:relative;z-index:1;margin-top:24px;font-size:19px;line-height:1.8;color:#333;max-width:560px}
  @media(max-width:900px){.sy-hero{padding:48px 20px;gap:32px}.sy-hero h1{font-size:32px}.sy-hero__watermark{display:none}}
</style>
<section id="c_static_001-17621535739280" class="sy-hero">
  <div class="sy-hero__media">
    @if ($img !== '')
    <img class="sy-hero__photo" src="{{ $img }}" alt="SANYUAN">
    @endif
  </div>
  <div class="sy-hero__body">
    <span class="sy-hero__watermark">30+</span>
    <h1>{{ $title }} <span class="hl">{{ $highlight }}</span> {{ $titleEnd }}</h1>
    <div class="sy-hero__desc">{{ $desc }}</div>
  </div>
</section>
