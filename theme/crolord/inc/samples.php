<?php
/** Two explicitly labelled sample articles and the Markets guest board. */
if (!defined('ABSPATH')) exit;

function fieldnotes_sample_posts() {
    return array(
        'markets-notebook' => array('section' => 'investing', 'title' => '把判断的来由，留给之后的自己', 'excerpt' => '一份示例笔记：用时间、问题和待验证的依据，整理一次市场观察。', 'content' => <<<'HTML'
<p><strong>示例笔记</strong> · 本文用于展示 Markets 的笔记排版，不对应任何真实持仓、交易或收益。</p>
<p>有时比价格更值得留下的，是当时为什么会停下来多看一眼。把图表和想法放在一起，之后回看，才知道哪些理解发生了变化。</p>
<h2>先写下问题</h2><p>这一次，我想观察的是波动本身，还是某个尚未核实的叙事？与其急着下结论，不妨先把问题写成一句完整的话。</p>
<blockquote><p>此刻的看法，只是等待验证的一个版本。</p></blockquote>
<h2>给依据留出位置</h2><ul><li>观察时间：填写自己的记录时间。</li><li>图表范围：写明股票代码、周期与复权方式。</li><li>数据来源：保留图表显示的行情时间，区分获取时间与数据时间。</li><li>待验证项：列出需要进一步查阅的公开资料。</li></ul>
<h2>回看时，再写一段</h2><p>哪些信息得到了确认？哪些想法需要修改？把后来的理解接着写在后面，保留原来的文字，不把每一次犹豫都修饰成笃定。</p>
<p>留言板留给交流；手记留给连续的思考。两种文字，各有自己的位置。</p>
HTML
        ),
        'cloudnative-practice' => array('section' => 'works', 'title' => '让一次部署，留下可追溯的路径', 'excerpt' => '一份可自行验证的示例实践：从 Deployment、就绪探针到一次本地访问，留下清晰的操作路径。', 'content' => <<<'HTML'
<p><strong>示例实践</strong> · 以下是用于栏目展示的演示文章与配置。没有声称已经在你的集群执行；可在自己的测试环境逐步验证。</p>
<p>一次小小的部署，也可以有完整的来路。把配置、验证和收尾放在同一篇记录里，下一次遇到相似的问题，就能从一个清楚的位置重新开始。</p>
<h2>一个足够小的起点</h2><p>准备可使用 kubectl 的测试集群，将以下内容保存为 <code>fieldnotes-demo.yaml</code>。镜像使用 nginx 的 stable 标签；需要严格复现时，请在自己的记录中固定经过验证的镜像摘要。</p>
<pre><code>apiVersion: apps/v1
kind: Deployment
metadata:
  name: fieldnotes-demo
spec:
  replicas: 2
  selector:
    matchLabels:
      app: fieldnotes-demo
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1
  template:
    metadata:
      labels:
        app: fieldnotes-demo
    spec:
      containers:
        - name: web
          image: nginx:stable
          ports:
            - containerPort: 80
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 2
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: fieldnotes-demo
spec:
  selector:
    app: fieldnotes-demo
  ports:
    - port: 80
      targetPort: 80</code></pre>
<h2>把验证写成步骤</h2><pre><code>kubectl apply -f fieldnotes-demo.yaml
kubectl rollout status deployment/fieldnotes-demo
kubectl get pods -l app=fieldnotes-demo -o wide
kubectl port-forward service/fieldnotes-demo 8080:80</code></pre>
<p>保持端口转发运行，在另一个终端请求 <code>http://127.0.0.1:8080</code>。预期可以访问 Nginx 默认页面；实际结果以自己的环境为准。</p>
<h2>让状态可以被解释</h2><p>就绪探针用来判断容器是否适合接收流量；就绪检查失败，不等于进程会被重启。记录 Pod 状态、事件和日志，通常比只写一句“部署成功”更有用。</p>
<pre><code>kubectl describe deployment fieldnotes-demo
kubectl get events --sort-by=.metadata.creationTimestamp
kubectl logs -l app=fieldnotes-demo --tail=30</code></pre>
<h2>最后，收好这次尝试</h2><p>记下 Kubernetes 版本、镜像摘要、配置变化和遇到的问题。确认测试结束后，可使用下面的命令移除本文创建的演示资源：</p><pre><code>kubectl delete -f fieldnotes-demo.yaml</code></pre>
<p>探针行为参考 <a href="https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/" target="_blank" rel="noopener noreferrer">Kubernetes 官方文档</a>。实践不必宏大，能够清楚地复现，就已经值得留下。</p>
HTML
        ),
    );
}

function fieldnotes_samples_install() {
    if (!current_user_can('publish_posts') || wp_doing_ajax() || get_option('fieldnotes_samples_200_done')) return;
    $lock = (int) get_option('fieldnotes_samples_200_lock');
    if ($lock && $lock < time()) delete_option('fieldnotes_samples_200_lock');
    if (!add_option('fieldnotes_samples_200_lock', time() + 90, '', false)) return;
    try {
        $page = fieldnotes_section_page('archive');
        if (!$page) return;
        $ids = get_option('fieldnotes_samples_200_ids', array());
        foreach (fieldnotes_sample_posts() as $key => $sample) {
            if (!empty($ids[$key]) && get_post($ids[$key])) continue;
            $existing = get_posts(array('post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_fieldnotes_sample_key', 'meta_value' => $key));
            if ($existing) { $ids[$key] = $existing[0]->ID; update_option('fieldnotes_samples_200_ids', $ids, false); continue; }
            $term = fieldnotes_boughs_term($sample['section']);
            if (!$term) return;
            $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_author' => get_current_user_id(), 'post_name' => 'fieldnotes-sample-' . $key, 'post_title' => $sample['title'], 'post_excerpt' => $sample['excerpt'], 'post_content' => $sample['content'], 'post_category' => array((int) $term->term_id), 'comment_status' => 'open', 'meta_input' => array('_fieldnotes_sample_key' => $key)), true);
            if (is_wp_error($id) || !$id) return;
            $ids[$key] = (int) $id; update_option('fieldnotes_samples_200_ids', $ids, false);
        }
        $opened = wp_update_post(array('ID' => $page->ID, 'comment_status' => 'open'), true);
        if (is_wp_error($opened) || !$opened) return;
        update_option('fieldnotes_samples_200_done', 1, false);
    } finally { delete_option('fieldnotes_samples_200_lock'); }
}
add_action('admin_init', 'fieldnotes_samples_install', 60);

function fieldnotes_market_board_request() {
    $page = fieldnotes_section_page('archive');
    if (!$page) return false;
    if (is_singular('page') && get_queried_object_id() === (int) $page->ID) return true;
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['comment_post_ID']) && is_scalar($_POST['comment_post_ID']) && ctype_digit((string) $_POST['comment_post_ID']) && (int) $_POST['comment_post_ID'] === (int) $page->ID;
}
function fieldnotes_market_guest_registration($value) {
    return fieldnotes_market_board_request() ? 0 : $value;
}
add_filter('pre_option_comment_registration', 'fieldnotes_market_guest_registration');

/** A new, explicitly requested sample pass. Never rewrite real articles or comments. */
function crolord_samples_install_210() {
    if (!current_user_can('publish_posts') || wp_doing_ajax() || get_option('crolord_samples_210_done')) return;
    $lock = (int) get_option('crolord_samples_210_lock');
    if ($lock && $lock < time()) delete_option('crolord_samples_210_lock');
    if (!add_option('crolord_samples_210_lock', time() + 90, '', false)) return;
    try {
        $page = fieldnotes_section_page('archive');
        if (!$page) return;
        foreach (fieldnotes_sample_posts() as $key => $sample) {
            $term = fieldnotes_boughs_term($sample['section']);
            if (!$term) return;
            $existing = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_fieldnotes_sample_key', 'meta_value' => $key));
            if ($existing) {
                // Repair only our labelled sample's category if an earlier migration misplaced it.
                wp_set_post_categories($existing[0]->ID, array((int) $term->term_id), true);
            } else {
                $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_author' => get_current_user_id(), 'post_title' => $sample['title'], 'post_name' => 'crolord-sample-' . $key, 'post_excerpt' => $sample['excerpt'], 'post_content' => $sample['content'], 'post_category' => array((int) $term->term_id), 'comment_status' => 'open', 'meta_input' => array('_fieldnotes_sample_key' => $key)), true);
                if (is_wp_error($id) || !$id) return;
            }
        }
        $messages = array(
            'one' => array('示例读者 · 山海', '示例留言：我想先把观察时的问题写下来，再回看图表。隔一段时间重读，也许能更清楚地看见当时的依据。', 'mountain'),
            'two' => array('示例读者 · 微光', '示例留言：能同时看到指标的数值和计算口径很方便。数据尚不足一个周期时保留空白，比匆忙补上一个数字更清楚。', 'leaf'),
            'three' => array('示例读者 · 行舟', '示例留言：这里可以用来交流图表的读法，也可以记录仍待核实的问题。把判断和事实分开写，留一点重新思考的余地。', 'traveler'),
        );
        foreach ($messages as $key => $message) {
            $found = get_comments(array('post_id' => $page->ID, 'status' => 'all', 'number' => 1, 'meta_key' => '_crolord_sample_comment', 'meta_value' => $key));
            if ($found) continue;
            $id = wp_insert_comment(array('comment_post_ID' => $page->ID, 'comment_author' => $message[0], 'comment_author_email' => 'sample-' . $key . '@example.invalid', 'comment_content' => $message[1], 'comment_type' => 'comment', 'comment_approved' => 1, 'user_id' => 0, 'comment_date' => current_time('mysql'), 'comment_date_gmt' => current_time('mysql', true)));
            if (!$id || is_wp_error($id)) return;
            add_comment_meta($id, '_crolord_sample_comment', $key, true);
            add_comment_meta($id, '_fct_avatar', $message[2], true);
        }
        $opened = wp_update_post(array('ID' => $page->ID, 'comment_status' => 'open'), true);
        if (!$opened || is_wp_error($opened)) return;
        update_option('crolord_samples_210_done', 1, false);
    } finally { delete_option('crolord_samples_210_lock'); }
}
add_action('admin_init', 'crolord_samples_install_210', 65);
