<?php
/** Article identity, topic context and home-only colophon. No content writes. */
if (!defined('ABSPATH')) exit;

function fieldnotes_clean_text($value) {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(wp_strip_all_tags($text));
}

function fieldnotes_category_profile($term) {
    if (function_exists('fieldnotes_boughs_sections') && isset($term->slug)) {
        $sections = fieldnotes_boughs_sections();
        $key = fieldnotes_boughs_key($term->slug);
        if (isset($sections[$key])) {
            $item = $sections[$key];
            return array('label' => $item['name'], 'subtitle' => $item['description'], 'intro' => $item['intro'], 'directions' => $item['topics'], 'related' => array_values(array_diff(array_keys($sections), array($key))));
        }
    }
    $profiles = array(
        'cities' => array('城市漫步', 'Cities', '把脚步放慢，看见城市的另一面。', '从街道的尺度到一家小店的光线，收藏城市日常中值得停留的细节。这里的笔记，留给不赶时间的阅读。', array('街道与建筑', '城市里的日常', '漫步与观察'), array('footprint', 'lifestyle', 'getaway')),
        'footprint' => array('旅行足迹', '足迹', '把走过的路，写成可以重返的记忆。', '旅行并不总是远行。沿途的风景、偶然的相遇与慢下来的片刻，都可以在这里找到位置。', array('沿途的风景', '地方与记忆', '慢旅行'), array('cities', 'getaway', 'lifestyle')),
        'getaway' => array('远方', '逍遥游', '给日常留一扇通往远方的窗。', '离开熟悉的节奏，看看另一处风景。收集旅途里不必赶路的时间，也记录回到日常后仍然记得的细节。', array('短途出走', '山海之间', '旅途片刻'), array('footprint', 'cities', 'small-pleasures')),
        'notes' => array('随笔', '贱志', '一些正在发生，也值得写下的想法。', '没有必须抵达的结论，也没有固定的写作题目。把一时的观察、阅读后的余温和慢慢想明白的事，留在这里。', array('日常观察', '阅读与想法', '片段与随记'), array('human-world', 'long-termism', 'lifestyle')),
        'lifestyle' => array('生活方式', '生活家', '在日常里，找到自己的节奏。', '从一顿饭、一张桌子到一种习惯，认真看待生活中反复出现的小事。舒服的日常，往往藏在这些细节里。', array('生活的细节', '习惯与节奏', '安静的选择'), array('small-pleasures', 'minimalism', 'things-i-buy')),
        'small-pleasures' => array('日常小事', '穷讲究', '让平凡的一天，舒服一点。', '不必宏大，也不必昂贵。留意那些让手边的生活更顺心的小选择，收好可以反复感受的日常愉悦。', array('手边的小事', '舒服与顺手', '日常的愉悦'), array('lifestyle', 'things-i-buy', 'minimalism')),
        'minimalism' => array('极简主义', '极简主义', '留下真正重要的，也留一点空白。', '整理物品，也整理注意力。这里讨论减少负担、清楚选择，以及如何把时间留给真正愿意继续的事情。', array('空间与秩序', '注意力', '少而合适'), array('lifestyle', 'long-termism', 'things-i-buy')),
        'reviews' => array('使用手记', 'Reviews', '认真使用之后，留下真实感受。', '从实际使用出发，记录一件东西如何进入日常。关注长期相处的细节，也保留不合适的部分。', array('使用体验', '细节与取舍', '长期相处'), array('things-i-buy', 'lifestyle', 'minimalism')),
        'things-i-buy' => array('买过与用过', '我买故我在', '安静而可靠的日常选择。', '记录买过、用过，并愿意继续留下的东西。比起新鲜感，更关心它们是否顺手、耐用，以及是否适合真实的生活。', array('日常用品', '选择与取舍', '长期使用'), array('reviews', 'lifestyle', 'minimalism')),
        'wordpress-themes' => array('WordPress 主题', 'WordPress 主题', '把想法，慢慢做成一个空间。', '关于页面、字体与阅读体验的设计记录，也收集实现过程中的方法、细节和取舍。', array('页面设计', '字体与阅读', '实现笔记'), array('wordpress-plugins', 'codex', 'notes')),
        'wordpress-plugins' => array('WordPress 插件', 'WordPress 插件', '让工具，恰好服务于需要。', '记录功能的使用、配置与实践。关心一个工具解决了什么问题，也关心它如何与整个网站自然相处。', array('功能与工具', '配置实践', '使用心得'), array('wordpress-themes', 'codex', 'reviews')),
        'long-termism' => array('长期主义', '长期主义', '把时间，留给愿意继续的事。', '记录与耐心、习惯和持续投入有关的想法。让计划回到每一次可以执行的小行动里。', array('习惯与积累', '耐心与选择', '长期行动'), array('notes', 'minimalism', 'human-world')),
        'human-world' => array('人世间', '人世间', '人与人之间，那些值得留意的事。', '从身边的人与事出发，保存相遇、理解与日常的温度。让观察慢一点，让表达更具体一些。', array('身边的人', '相遇与理解', '日常记录'), array('notes', 'lifestyle', 'cities')),
        'investing' => array('投资理财', '投资理财', '关于选择、风险与时间的学习记录。', '整理阅读和实践中的想法，记录判断的依据与之后的复盘。这里收集思考过程，不给生活许诺捷径。', array('阅读与学习', '判断与复盘', '风险与时间'), array('long-termism', 'notes')),
        'codex' => array('灵感札记', 'Codex', '给尚在成形的想法，留一个位置。', '从一个问题、一次尝试或一段代码开始，记录灵感如何落地，也留下继续探索的线索。', array('问题与尝试', '工具与创造', '灵感片段'), array('wordpress-themes', 'wordpress-plugins', 'notes')),
    );
    $slug = isset($term->slug) ? (string) $term->slug : '';
    $name = isset($term->name) ? fieldnotes_clean_text($term->name) : '';
    $row = $profiles[$slug] ?? array($name, $name, '沿着一个主题，慢慢展开阅读。', '把相关的记录收在一起，让每一篇笔记都有可以继续阅读的线索。在不同的观察与片段之间，找到自己的阅读节奏。', array('主题里的记录', '观察与想法', '值得回看的片段'), array());
    return array('label' => strtolower($name) === strtolower($row[1]) ? $row[0] : $name, 'subtitle' => $row[2], 'intro' => $row[3], 'directions' => $row[4], 'related' => $row[5]);
}

function fieldnotes_category_label($term) {
    return fieldnotes_category_profile($term)['label'];
}

function fieldnotes_category_summary($term) {
    $description = isset($term->description) ? fieldnotes_clean_text($term->description) : '';
    return $description !== '' ? $description : fieldnotes_category_profile($term)['subtitle'];
}

function fieldnotes_related_categories($term) {
    $slugs = fieldnotes_category_profile($term)['related'];
    if (!$slugs) return array();
    $items = get_terms(array('taxonomy' => 'category', 'slug' => $slugs, 'hide_empty' => true, 'number' => 3));
    return is_wp_error($items) ? array() : $items;
}

function fieldnotes_reading_minutes($post_id = 0) {
    $post = get_post($post_id ?: get_the_ID());
    if (!$post || post_password_required($post)) return 0;
    $text = wp_strip_all_tags(strip_shortcodes($post->post_content));
    $han = preg_match_all('/\p{Han}/u', $text);
    $words = preg_match_all('/[\p{L}\p{N}]+/u', preg_replace('/\p{Han}/u', ' ', $text));
    return max(1, (int) ceil($han / 450 + $words / 220));
}

function fieldnotes_article_author($author_id) {
    $author_id = absint($author_id);
    $name = fieldnotes_clean_text(get_the_author_meta('display_name', $author_id));
    if ($name === '') $name = fieldnotes_clean_text(get_bloginfo('name'));
    $bio = fieldnotes_clean_text(get_the_author_meta('description', $author_id));
    return array('id' => $author_id, 'name' => $name, 'bio' => $bio ?: __('记录生活、旅行，以及慢慢成形的想法。', 'crolord'), 'initial' => wp_html_excerpt($name, 1, ''), 'url' => get_author_posts_url($author_id));
}

function fieldnotes_author_portrait($author, $size = 48) {
    $avatar = get_avatar($author['id'], $size, '', '', array('class' => 'reading-author-image', 'loading' => 'lazy'));
    return '<span class="reading-author-portrait" aria-hidden="true"><span>' . esc_html($author['initial']) . '</span>' . ($avatar ?: '') . '</span>';
}

function fieldnotes_show_site_footer() {
    return is_front_page() && !is_paged() && (int) get_query_var('page') <= 1;
}


/** Contents belong to Interludes and its child categories, never to a locked entry. */
function fieldnotes_reading_contents_enabled($post_id = 0) {
    $post_id = absint($post_id ?: get_queried_object_id());
    if (!$post_id || get_post_type($post_id) !== 'post' || post_password_required($post_id)) return false;
    return crolord_request_value(__FUNCTION__ . ':' . $post_id, function () use ($post_id) {
        $term = fieldnotes_boughs_term('works');
        if (!$term || is_wp_error($term)) return false;
        $ids = array((int) $term->term_id);
        $children = get_term_children($term->term_id, 'category');
        if (!is_wp_error($children)) $ids = array_merge($ids, array_map('intval', $children));
        return has_category($ids, $post_id);
    });
}
