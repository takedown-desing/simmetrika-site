// Генерирует public/admin/config.yml (Sveltia CMS, формат Decap/Netlify CMS) перед сборкой.
// Выпадающие списки (категории, ткани, цвета, механизмы, шоурумы, товары, адреса страниц) берутся из src/cms,
// поэтому в админке всегда те же значения, что на сайте. Пишется JSON-синтаксисом: это валидный YAML.
import fs from 'node:fs';
import path from 'node:path';

const REPO = process.env.CMS_REPO || 'takedown-desing/simmetrika-site';
const BASE = (process.env.BASE_PATH ?? '/simmetrika-site').replace(/^\/+|\/+$/g, '');
const SITE_URL = (process.env.SITE_URL || 'https://takedown-desing.github.io').replace(/\/$/, '') + (BASE ? '/' + BASE : '') + '/';
const out = path.resolve('public/admin/config.yml');
// битый файл не роняет генерацию: о нём понятно сообщит validate-cms.mjs
const J = (p) => { try { return JSON.parse(fs.readFileSync(path.resolve(p), 'utf8')); } catch { return {}; } };
const col = (c) => (fs.existsSync(`src/cms/${c}`) ? fs.readdirSync(`src/cms/${c}`).filter((f) => f.endsWith('.json')).map((f) => ({ file: f.slice(0, -5), ...J(`src/cms/${c}/${f}`) })) : []);

const fab = J('src/cms/settings/fabrics.json');
const showrooms = J('src/cms/settings/showrooms.json').showrooms || [];
const categories = col('categories');
const products = col('products');
const opt = (value, label) => ({ value, label: label || value });
const TAG_OPTS = [opt('dlya-gostinoy', 'Для гостиной'), opt('dizaynerskie', 'Дизайнерские'), opt('bolshie', 'Большие (ставится и автоматически от 250 см)')];
// Связи (relation): значения берутся из данных прямо в админке, без пересборки сайта.
// Новая категория, цвет, ткань, механизм или шоурум доступны для выбора сразу после сохранения.
const relRaw = (name, label, collection, value_field, display_fields, x = {}) => ({ name, label, widget: 'relation', required: false, collection, value_field, display_fields, search_fields: display_fields, ...x });
// категория хранится по имени файла записи: смена адреса категории не ломает её товары
const REL_CAT = (x) => relRaw('category', 'Категория', 'categories', '{{slug}}', ['name'], { dropdown_threshold: 8, ...x });
const REL_MAT = (name = 'material', label = 'Материал обивки', x) => relRaw(name, label, 'settings', 'fabrics.*.material', ['fabrics.*.name'], { file: 'fabrics', ...x });
const REL_COLOR = (name = 'color', label = 'Цвет', x) => relRaw(name, label, 'settings', 'colors.*.slug', ['colors.*.name'], { file: 'fabrics', dropdown_threshold: 12, ...x });
const REL_MECH = (x) => relRaw('mechanism', 'Механизм', 'settings', 'mechanisms.*.slug', ['mechanisms.*.name'], { file: 'fabrics', ...x });
const REL_SHOW = (x) => relRaw('showroom', 'Шоурум', 'settings', 'showrooms.*.slug', ['showrooms.*.name'], { file: 'showrooms', ...x });
// товар выбирается по имени файла записи: оно не меняется при смене адреса или категории
const REL_PRODUCTS = (x) => relRaw('product_ids', 'Товары по теме', 'products', '{{slug}}', ['h1'], { multiple: true, search_fields: ['h1', 'name'], ...x });

// ── конструкторы полей ──
const str = (name, label, x = {}) => ({ name, label, widget: 'string', required: false, ...x });
const txt = (name, label, x = {}) => ({ name, label, widget: 'text', required: false, ...x });
const num = (name, label, x = {}) => ({ name, label, widget: 'number', value_type: 'int', required: false, ...x });
const bool = (name, label, def = false, x = {}) => ({ name, label, widget: 'boolean', default: def, required: false, ...x });
const sel = (name, label, options, x = {}) => ({ name, label, widget: 'select', options, required: false, ...x });
const img = (name, label, x = {}) => ({ name, label, widget: 'image', required: false, choose_url: true, ...x });
const hid = (name) => ({ name, label: name, widget: 'hidden' }); // скрытые служебные поля: хранятся в записи, в форме не видны
const list = (name, label, fields, x = {}) => ({ name, label, widget: 'list', required: false, collapsed: true, fields, ...x });
const plist = (name, label, field, x = {}) => ({ name, label, widget: 'list', required: false, collapsed: true, field, ...x });
const obj = (name, label, fields, x = {}) => ({ name, label, widget: 'object', required: false, collapsed: true, fields, ...x });
const SLUG_P = ['^[a-z0-9]+(-[a-z0-9]+)*$', 'Только строчная латиница, цифры и дефис, например divan-milano'];
const CODE_P = [SLUG_P[0], 'Только строчная латиница, цифры и дефис, например grafit'];
const URL_P = ['^/([a-z0-9]+(-[a-z0-9]+)*/)*$', 'Адрес вида /garantiya/ или /catalog/velyur/: строчная латиница, слэш в начале и в конце'];
const go = (c) => `${BASE ? BASE + '/' : ''}go/${c}/{{slug}}/`;

const DIRECTIVES = 'Текст в формате Markdown: ## Заголовок, ### Подзаголовок, **жирный**, [ссылка](/catalog/pryamye-divany/), списки «- пункт». Вставки (каждая с новой строки и закрывается строкой «:::»): '
  + ':::products{rule="category=pryamye-divany"}: карточки товаров по правилу (category, mechanism, material, color, tag, in_stock, sample, hit, has_sleeping_place, length_gte; через запятую); '
  + ':::filters: фильтры; :::tiles{items="/catalog/velyur/,/sale/"}: плитки разделов; :::chips{items="…"}: ссылки-чипы; :::spec-table{rule="…"}: таблица размеров; '
  + ':::swatches: ткани; :::showrooms: шоурумы; :::map: карта; :::news{limit="3"}: новости; :::form{type="question"}: форма; '
  + ':::steps{title="…"} список шагов :::, :::compare{title="…"} таблица :::, :::cta{href="/na-zakaz/" label="…"} призыв :::, :::note заметка :::, :::scheme{kind="mechanisms"} схема :::. '
  + 'Ошибки в тексте покажет страница «SEO-проверка» в админке.';

// SEO-блок: одинаков для всех страниц
const seo = [
  str('h1', 'Заголовок H1', { hint: 'Один на страницу, с главным запросом. Если пусто: берётся название' }),
  str('title', 'Title (заголовок вкладки и сниппета)', { maxlength: 80, hint: '50–60 знаков, главный запрос в начале, разделитель « | ». Длинное тире не используйте' }),
  txt('description', 'Description (описание в поиске)', { maxlength: 200, hint: '140–160 знаков: что, для кого, цена или срок, где купить' }),
  str('og_title', 'Заголовок для соцсетей (og:title)', { hint: 'Если пусто: берётся Title' }),
  txt('og_description', 'Описание для соцсетей (og:description)'),
  str('primary_kw', 'Главный запрос страницы', { hint: 'Для контроля: должен быть в H1, Title и первом абзаце' }),
  bool('noindex', 'Закрыть от индексации (noindex)', false, { hint: 'Страница останется на сайте, но не попадёт в поиск' }),
  str('canonical', 'Canonical (адрес основной версии)', { hint: 'Только если страница дублирует другую: /catalog/pryamye-divany/' }),
];
const faq = [str('faq_title', 'Заголовок блока вопросов'), list('faq', 'Вопросы и ответы (FAQ, попадают в микроразметку)', [str('q', 'Вопрос', { required: true }), txt('a', 'Ответ (40–60 слов, без ссылок)', { required: true })], { label_singular: 'Вопрос', summary: '{{fields.q}}' })];
const ctaBtn = (n, l) => obj(n, l, [str('label', 'Текст кнопки'), str('href', 'Ссылка (/catalog/ или #forma)'), sel('action', 'Действие вместо ссылки', [opt('callback', 'Открыть форму обратного звонка')])]);
const pageFields = [
  str('eyebrow', 'Надзаголовок (маленькая красная строка над H1)'),
  txt('lede', 'Подзаголовок под H1 (лид)', { hint: '1–2 предложения, до 220 знаков' }),
  list('highlights', 'Цифры под заголовком (3–4 плитки)', [str('value', 'Значение', { hint: 'Например: 8 моделей' }), str('label', 'Подпись')], { label_singular: 'Цифра', summary: '{{fields.value}} {{fields.label}}' }),
  obj('hero_cta', 'Кнопки под заголовком', [ctaBtn('primary', 'Главная кнопка'), ctaBtn('secondary', 'Вторая кнопка')]),
  txt('body', 'Текст страницы', { hint: DIRECTIVES }),
  ...faq,
  list('related', 'Блок «Смотрите также»', [str('url', 'Адрес', { hint: 'Например: /catalog/divany-krovati/' }), str('title', 'Заголовок'), str('text', 'Одна строка')], { label_singular: 'Ссылка', summary: '{{fields.title}}' }),
  list('images', 'Картинки страницы', [str('slot', 'Место', { hint: 'hero: первый экран; tile-…: плитки; inline-1: в тексте' }), img('src', 'Файл'), str('alt', 'Описание картинки (alt)', { hint: 'Что на фото, с названием модели' }), hid('query'), hid('kind')], { label_singular: 'Картинка', summary: '{{fields.slot}}' }),
];
const internal = ['secondary_kws', 'assumptions', 'date_modified', 'breadcrumbs'].map(hid);
const ruleFields = [
  REL_CAT(), REL_MECH(), REL_MAT(), REL_COLOR(),
  sel('tag', 'Метка товара', TAG_OPTS), num('length_gte', 'Длина от, см'), bool('in_stock', 'Только в наличии'), bool('sample', 'Только выставочные образцы'),
  bool('has_sleeping_place', 'Только со спальным местом'), bool('has_storage', 'Только с ящиком для белья'), bool('hit', 'Только хиты'),
];

const config = {
  backend: {
    name: 'github', repo: REPO, branch: 'main',
    // правки сохраняются коммитами с [skip ci]; сайт пересобирается по кнопке «Опубликовать изменения» (repository_dispatch → deploy.yml)
    skip_ci: true,
    commit_messages: { create: 'Админка: создано {{collection}} «{{slug}}»', update: 'Админка: изменено {{collection}} «{{slug}}»', delete: 'Админка: удалено {{collection}} «{{slug}}»', uploadMedia: 'Админка: загружен файл {{path}}', deleteMedia: 'Админка: удалён файл {{path}}' },
  },
  site_url: SITE_URL, display_url: SITE_URL, logo: { src: SITE_URL + 'favicon.svg' },
  media_folder: 'public/images/uploads', public_folder: '/images/uploads',
  // загруженные фото: имя латиницей (Тестовое Фото.jpg → testovoe-foto.webp), WebP до 1600 px прямо в браузере, до 20 МБ на файл
  media_libraries: { default: { config: { slugify_filename: true, max_file_size: 20000000, transformations: { raster_image: { format: 'webp', quality: 82, width: 1600, height: 1600 } } } } },
  slug: { encoding: 'ascii', clean_accents: true, sanitize_replacement: '-' },
  collections: [
    {
      name: 'products', label: 'Товары', label_singular: 'Товар', folder: 'src/cms/products', create: true, delete: true, format: 'json', extension: 'json',
      identifier_field: 'name', slug: '{{fields.category}}--{{fields.slug}}', summary: '{{h1}}', preview_path: go('products'),
      sortable_fields: ['name', 'category', 'order', 'price_from'], view_groups: [{ label: 'По категории', field: 'category' }, { label: 'Скрытые', field: 'hidden' }],
      description: 'Карточка товара: адрес /catalog/<категория>/<адрес>/. Цена «от» указывается для рогожки, велюр и жаккард пересчитываются коэффициентами из «Ткани и цвета». Без цены на сайте «цена по запросу».',
      fields: [
        str('name', 'Название модели', { required: true, hint: 'Например: Эджио' }),
        str('slug', 'Адрес (латиницей)', { required: true, pattern: SLUG_P, hint: 'Например: edzhio → /catalog/pryamye-divany/edzhio/. Смена адреса опубликованного товара создаёт переадресацию автоматически' }),
        REL_CAT({ required: true, hint: 'Новая категория появится в списке сразу после её сохранения' }),
        bool('hidden', 'Скрыть с сайта', false, { hint: 'Карточка пропадёт с сайта, старый адрес переадресуется в категорию' }),
        num('order', 'Порядок в каталоге', { hint: 'Меньше: выше' }),
        num('price_from', 'Цена «от», ₽ (рогожка)', { hint: 'Пусто: «цена по запросу»' }),
        bool('is_hit', 'Хит продаж (на главной)'),
        sel('tags', 'Метки для подборок', TAG_OPTS, { multiple: true }),
        REL_MECH({ label: 'Механизм трансформации', default: 'none', required: true }),
        obj('sleeping', 'Спальное место, см', [num('w', 'Ширина'), num('l', 'Длина')], { collapsed: false, hint: 'Пусто: без спального места' }),
        num('length_cm', 'Длина, см'), num('depth_cm', 'Глубина, см'), num('height_cm', 'Высота, см'), num('seat_height_cm', 'Высота посадки, см'),
        bool('has_storage', 'Есть ящик для белья'), bool('sides', 'Угловой: выбор стороны угла (левый/правый)'),
        str('lead_time_days', 'Срок изготовления, дней', { default: '14–21' }),
        obj('in_stock', 'В наличии на складе', [REL_MAT('material', 'Материал'), REL_COLOR()], { collapsed: false, hint: 'Пусто: «под заказ»' }),
        num('warranty_months', 'Гарантия, месяцев', { default: 18 }),
        str('model_family', 'Семейство модели', { hint: 'Одинаковое у исполнений одной модели в разных категориях (например, edzhio): блок «Эта модель в других исполнениях»' }),
        plist('modules', 'Модули (для модульных)', str('m', 'Модуль')),
        plist('photos', 'Фото (первое будет главным)', img('p', 'Фото', { hint: 'JPG/WebP 1200–1600 px по длинной стороне' }), { collapsed: false }),
        str('alt_template', 'Шаблон alt для фото', { hint: 'Например: Прямой диван Эджио, {материал} {цвет}: подставятся выбранные ткань и цвет' }),
        list('samples', 'Выставочные образцы (распродажа)', [REL_SHOW({ required: true }), REL_MAT('material', 'Материал'), REL_COLOR(), num('discount_pct', 'Скидка, %', { required: true, min: 1, max: 90, hint: 'Без шоурума и скидки образец на сайт не попадёт' }), num('old_price', 'Цена без скидки, ₽'), num('sample_price', 'Цена образца, ₽')], { label_singular: 'Образец', summary: '{{fields.showroom}} −{{fields.discount_pct}}%' }),
        txt('construction', 'Конструкция (каркас, наполнитель, опоры)'),
        ...seo,
        txt('lede', 'Подзаголовок карточки'),
        list('features', 'Преимущества (карточки в описании)', [str('title', 'Заголовок'), txt('text', 'Пояснение')], { label_singular: 'Преимущество', summary: '{{fields.title}}' }),
        txt('body', 'Описание модели', { hint: 'Вводный абзац, затем разделы «### Конструкция и материалы», «### Для какой комнаты»: они станут карточками. ' + DIRECTIVES }),
        ...faq,
        ...internal,
      ],
    },
    {
      name: 'categories', label: 'Категории каталога', label_singular: 'Категория', folder: 'src/cms/categories', create: true, delete: true, format: 'json', extension: 'json',
      identifier_field: 'name', slug: '{{fields.slug}}', summary: '{{name}} · /catalog/{{slug}}/', preview_path: go('categories'), sortable_fields: ['order', 'name'],
      description: 'Категория = страница /catalog/<адрес>/ со всеми товарами, у которых выбрана эта категория. Новая категория появится в выборе категории у товаров сразу после сохранения и сама добавится в меню «Каталог товаров».',
      fields: [
        str('name', 'Название', { required: true }), str('slug', 'Адрес (латиницей)', { required: true, unique: true, pattern: SLUG_P, hint: 'Например: pryamye-divany → /catalog/pryamye-divany/' }),
        num('order', 'Порядок'), bool('in_menu', 'Показывать в меню «Каталог товаров»', true, { hint: 'Если категории нет в «Настройки → Меню сайта», она добавится в конец первой колонки' }), bool('hidden', 'Скрыть с сайта'),
        ...seo, ...pageFields, hid('listing'), ...internal,
      ],
    },
    {
      name: 'collections', label: 'Подборки и фильтры', label_singular: 'Подборка', folder: 'src/cms/collections', create: true, delete: true, format: 'json', extension: 'json',
      identifier_field: 'h1', slug: '{{fields.url}}', summary: '{{h1}} · {{url}}', preview_path: go('collections'), sortable_fields: ['url', 'h1'],
      description: 'SEO-подборки: диваны-кровати, механизмы, ткани, цвета, «для гостиной», «в наличии». Товары отбираются автоматически по правилу. Подборка, в которую попало меньше 3 товаров, закрывается от индексации.',
      fields: [
        str('url', 'Адрес страницы', { required: true, unique: true, pattern: URL_P, hint: 'Например: /catalog/divany-krovati/pantograf/: латиница, со слэшами' }),
        sel('type', 'Тип', [opt('collection', 'Подборка'), opt('filter', 'Фильтр-посадочная')], { default: 'filter', required: true }),
        bool('hidden', 'Скрыть с сайта'),
        obj('listing', 'Какие товары показывать', [obj('rule', 'Правило отбора (все условия вместе)', ruleFields, { collapsed: false, required: true }), sel('sort_default', 'Сортировка', [opt('popularity', 'По популярности'), opt('price_asc', 'Дешевле'), opt('price_desc', 'Дороже'), opt('discount', 'По скидке')], { default: 'popularity' })], { collapsed: false, required: true }),
        ...seo, str('robots', 'Robots (служебное)', { hint: 'noindex,follow: закрыть; пусто: по правилу 3 товаров' }), str('index_when', 'Когда открыть индексацию (заметка)'),
        ...pageFields, ...internal,
      ],
    },
    {
      name: 'pages', label: 'Страницы', label_singular: 'Страница', folder: 'src/cms/pages', create: true, delete: true, format: 'json', extension: 'json',
      identifier_field: 'h1', slug: '{{fields.id}}', summary: '{{h1}} · {{url}}', preview_path: go('pages'), sortable_fields: ['url', 'h1'],
      description: 'Главная, каталог, распродажа, «на заказ», доставка, о фабрике, контакты, новости (список), заявка, политика, 404 и любые новые страницы (гарантия, акции, статьи). Новая страница: заполните «Код страницы», адрес и H1.',
      fields: [
        str('id', 'Код страницы (латиницей)', { required: true, unique: true, pattern: SLUG_P, hint: 'Например: garantiya (имя файла записи)' }),
        str('url', 'Адрес страницы', { required: true, unique: true, pattern: URL_P, hint: 'Например: /garantiya/: латиница, со слэшами' }),
        sel('type', 'Шаблон', [opt('service', 'Текстовая страница'), opt('landing', 'Посадочная с товарами'), opt('about', 'О компании'), opt('contact', 'Контакты'), opt('hub', 'Каталог (хаб)'), opt('home', 'Главная'), opt('news-list', 'Список новостей'), opt('legal', 'Документ (политика)'), opt('cart', 'Заявка (служебная)'), opt('error', 'Ошибка 404 (служебная)')], { default: 'service', required: true }),
        bool('hidden', 'Скрыть с сайта'),
        ...seo, str('robots', 'Robots (служебное)'),
        ...pageFields, hid('listing'),
        ...['legal_review', 'legal_review_note', 'http_status', 'policy_date', 'operator', 'placeholders', 'anchors', 'consent_checkbox_text', 'consent_checkbox_rules', 'consent_error_text', 'cookie_notice', 'ui_texts', 'external_links', 'x_robots_tag', 'og', 'form_hidden'].map(hid),
        ...internal,
      ],
    },
    {
      name: 'news', label: 'Новости и акции', label_singular: 'Новость', folder: 'src/cms/news', create: true, delete: true, format: 'json', extension: 'json',
      identifier_field: 'h1', slug: '{{fields.slug}}', summary: '{{date}} · {{h1}}', preview_path: go('news'), sortable_fields: ['date', 'h1'],
      description: 'Новость или акция: /news/<адрес>/. Последние записи выводятся на главной и в разделе «Новости».',
      fields: [
        str('slug', 'Адрес (латиницей)', { required: true, unique: true, pattern: SLUG_P }),
        sel('news_type', 'Тип', [opt('news', 'Новость'), opt('promo', 'Акция')], { default: 'news', required: true }),
        { name: 'date', label: 'Дата публикации', widget: 'datetime', format: 'YYYY-MM-DD', time_format: false, required: true },
        { name: 'date_end', label: 'Акция до (если есть)', widget: 'datetime', format: 'YYYY-MM-DD', time_format: false, required: false },
        bool('hidden', 'Скрыть с сайта'),
        img('cover', 'Обложка'), str('cover_alt', 'Описание обложки (alt)'), txt('announce', 'Анонс (до 200 знаков)'),
        REL_PRODUCTS(),
        ...seo, ...pageFields, hid('url'), ...internal,
      ],
    },
    {
      name: 'settings', label: 'Настройки сайта', files: [
        { name: 'company', label: 'Компания и контакты', file: 'src/cms/settings/company.json', format: 'json', fields: [
          str('brand', 'Название', { required: true }), str('phone_main', 'Основной телефон', { required: true }), str('phone_second', 'Второй телефон'), str('email', 'E-mail'), str('vk', 'Группа ВКонтакте (ссылка)'),
          str('work_hours_calls', 'Часы приёма звонков', { hint: 'Например: 10:00–21:00' }), str('callback_promise', 'Обещание перезвона', { hint: 'Например: перезвоним в течение 1 часа…' }),
          num('warranty_months', 'Гарантия по умолчанию, месяцев'), num('service_life_years', 'Срок службы, лет'), txt('legal', 'Реквизиты'), txt('preview_note', 'Плашка сверху сайта', { hint: 'Пусто: плашки нет' }),
        ] },
        { name: 'delivery', label: 'Доставка и оплата (цифры)', file: 'src/cms/settings/delivery.json', format: 'json', fields: [
          num('moscow_mkad_rub', 'В пределах МКАД, ₽'), num('outside_mkad_per_km_rub', 'За МКАД, ₽ за км'), str('lift_floor_rub', 'Подъём на этаж'), str('assembly', 'Сборка'), str('pickup', 'Самовывоз'),
          str('in_stock_days', 'Доставка из наличия, дней'), str('made_to_order_days', 'Изготовление под заказ, дней'), num('standard_days', 'Стандартный срок, дней'), num('prepayment_custom_pct', 'Предоплата под размер, %'),
          plist('payment', 'Способы оплаты', str('p', 'Способ')), num('region_limit_km', 'Доставка по области до, км'),
        ] },
        { name: 'showrooms', label: 'Шоурумы', file: 'src/cms/settings/showrooms.json', format: 'json', fields: [
          list('showrooms', 'Шоурумы', [str('slug', 'Код (латиницей)', { required: true, pattern: CODE_P, hint: 'Только латиница и дефис. Код уже используемой записи не меняйте: на него ссылаются товары' }), str('name', 'Название', { required: true }), str('address', 'Адрес', { required: true }), str('hours', 'Часы работы'), str('phone', 'Телефон'), bool('is_main', 'Главный шоурум'), bool('hidden', 'Скрыть'), { name: 'lat', label: 'Широта', widget: 'number', value_type: 'float', required: false }, { name: 'lon', label: 'Долгота', widget: 'number', value_type: 'float', required: false }], { label_singular: 'Шоурум', summary: '{{fields.name}}', collapsed: false }),
        ] },
        { name: 'fabrics', label: 'Ткани, цвета, механизмы', file: 'src/cms/settings/fabrics.json', format: 'json', fields: [
          list('fabrics', 'Материалы обивки', [str('material', 'Код (латиницей)', { required: true, pattern: CODE_P, hint: 'Только латиница и дефис. Код уже используемой записи не меняйте: на него ссылаются товары' }), str('name', 'Название', { required: true }), str('collection', 'Коллекция'), { name: 'price_k', label: 'Коэффициент цены (рогожка = 1)', widget: 'number', value_type: 'float', required: true }, REL_COLOR('colors', 'Доступные цвета', { multiple: true }), str('props', 'Свойства')], { label_singular: 'Материал', summary: '{{fields.name}} ×{{fields.price_k}}' }),
          list('colors', 'Цвета', [str('slug', 'Код (латиницей)', { required: true, pattern: CODE_P, hint: 'Только латиница и дефис. Код уже используемой записи не меняйте: на него ссылаются товары' }), str('name', 'Название', { required: true }), str('plural', 'Во множественном числе («Серые»)'), { name: 'hex', label: 'Цвет кружка', widget: 'color', required: false }], { label_singular: 'Цвет', summary: '{{fields.name}}' }),
          list('mechanisms', 'Механизмы', [str('slug', 'Код (латиницей)', { required: true, pattern: CODE_P, hint: 'Только латиница и дефис. Код уже используемой записи не меняйте: на него ссылаются товары' }), str('name', 'Название', { required: true })], { label_singular: 'Механизм', summary: '{{fields.name}}' }),
        ] },
        { name: 'menu', label: 'Меню сайта', file: 'src/cms/settings/menu.json', format: 'json', fields: [
          list('nav', 'Меню слева от логотипа', [str('title', 'Пункт', { required: true }), str('url', 'Адрес', { required: true }), bool('accent', 'Выделить красным')], { label_singular: 'пункт', summary: '{{fields.title}}' }),
          list('nav_right', 'Меню справа от логотипа', [str('title', 'Пункт', { required: true }), str('url', 'Адрес', { required: true })], { label_singular: 'пункт', summary: '{{fields.title}}' }),
          list('catalog_menu', 'Меню «Каталог товаров» (колонки)', [str('title', 'Заголовок колонки', { required: true }), list('items', 'Ссылки', [str('title', 'Текст', { required: true }), str('url', 'Адрес', { required: true })], { label_singular: 'ссылку', summary: '{{fields.title}}' })], { label_singular: 'колонку', summary: '{{fields.title}}' }),
        ] },
        { name: 'redirects', label: 'Переадресации', file: 'src/cms/settings/redirects.json', format: 'json', fields: [
          list('redirects', 'Ручные переадресации', [str('from', 'Старый адрес', { hint: 'Например: /akcii/ или /straight.html' }), str('to', 'Куда вести', { hint: 'Например: /sale/' })], { label_singular: 'переадресацию', summary: '{{fields.from}} → {{fields.to}}', hint: 'При смене адреса, скрытии или удалении записи переадресация ставится автоматически. Сюда: только особые случаи.' }),
        ] },
      ],
    },
  ],
};
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, '# Сгенерировано scripts/gen-cms-config.mjs, не править вручную (JSON = валидный YAML)\n' + JSON.stringify(config, null, 2) + '\n');
console.log('[gen-cms-config] коллекций:', config.collections.length, '| категорий:', categories.length, '| товаров:', products.length);
