<?php

namespace WeAreFar\WPNetlify;

use Carbon\Carbon;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Request;
use WP_Error;
use WP_REST_Server;

class Deploy
{
    private ?string $buildHook;
    private ?string $jwsToken;

    public function __construct(string $file)
    {
        $this->buildHook = $_ENV['NETLIFY_BUILD_HOOK'] ?? null;
        $this->jwsToken = $_ENV['NETLIFY_JWS_TOKEN'] ?? null;

        add_action('wp_dashboard_setup', array($this, 'addDashboardWidget'));
        add_action('admin_menu', array($this, 'registerSubmenu'));
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        register_activation_hook($file, array($this, 'createDeploysTable'));
    }

    public function addDashboardWidget()
    {
        wp_add_dashboard_widget(
            'deploy_widget',
            'Netlify Deploys',
            array($this,'deployWidget')
        );
    }

    public function deployWidget()
    {
        $url = admin_url('tools.php?page=deploy');
        ?>
        <?php if ($this->buildHook): ?>
        <form method="POST" action="<?= $url ?>" style="display: inline;">
            <?php wp_nonce_field('deploy', 'deploy_nonce'); ?>
            <button class="button button-primary" name="action" value="deploy">Deploy now</button>
        </form>
        <?php endif; ?>
        <a href="<?= $url; ?>" class="button">View deploy log</a>
        <?php
    }

    public function registerSubmenu()
    {
        add_submenu_page(
            'tools.php',
            'Netlify Deploys',
            'Netlify Deploys',
            'edit_posts',
            'deploy',
            array($this, 'deployPageOutput')
        );
    }

    public function enqueueAdminScripts()
    {
        wp_enqueue_style('wp-netlify-deploys', plugin_dir_url(__FILE__).'style.css');
    }

    public function deployPageOutput()
    {
        ?>
        <div class="wrap">
            <h1>Netlify deploys</h1>
        <?php

        $missingEnvVars = array_keys(array_filter([
            'NETLIFY_BUILD_HOOK' => $this->buildHook,
            'NETLIFY_JWS_TOKEN' => $this->jwsToken,
        ], fn ($value) => empty($value)));

        if ($missingEnvVars) : ?>
            <div class="notice notice-warning">
                <p><?php echo esc_html__('Netlify environment variables are not properly set. Please check your .env file.', 'wp-netlify-deploy'); ?></p>
                <p><?php echo esc_html(sprintf(
                    __('Missing required environment variable(s): %s.', 'wp-netlify-deploy'),
                    implode(', ', $missingEnvVars)
                )); ?></p>
            </div>
        <?php endif; ?>

        <?php
        if (($_REQUEST['action'] ?? null) === 'deploy') {
            if (!wp_verify_nonce($_REQUEST['deploy_nonce'] ?? '', 'deploy')) {
                return;
            }

            $ch = curl_init();

            curl_setopt_array($ch, array(
                CURLOPT_URL => $this->buildHook,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => []
            ));

            curl_exec($ch);

            ?>
            <div class="notice notice-info is-dismissible">
                <p><?= __('Deploy triggered.', 'wp-netlify-deploy'); ?></p>
            </div>
            <?php
        }
        ?>
        <div class="deploys-table">
            <div class="deploys-toolbar">
                <div>
                    <h2>Deploy log</h2>
                </div>
                <?php if ($this->buildHook): ?>
                <div class="deploys-buttons">
                    <form method="POST">
                        <?php wp_nonce_field('deploy', 'deploy_nonce'); ?>
                        <button class="button button-primary" name="action" value="deploy">Trigger deploy</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php

        global $wpdb;

        $table_name = $wpdb->prefix . 'netlify_deploys';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) !== $table_name) {
            $this->createDeploysTable();
        }

        $deploys = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i ORDER BY id DESC', $table_name)
        );

        if ( $deploys ) {
            echo '<ul class="deploys-list">';
            foreach ( $deploys as $deploy ) {
                printf(
                    '<li><div><p><span>%1$s</span>: <span>%2$s</span> <span class="badge badge-%3$s">%3$s</span></p><p class="meta">%5$s</p></div><div class="fit"><time>%4$s</time><p class="meta">%6$s</p></div></li>',
                    'ready' == $deploy->state ?
                        sprintf('<a href="%1$s" target="blank" rel="noopener noreferrer" title="Go to preview">%2$s</a>', $deploy->deploy_ssl_url, ucwords(str_replace('-', ' ', $deploy->context))) :
                        ucwords(str_replace('-', ' ', $deploy->context)),
                    $deploy->branch,
                    $deploy->state,
                    Carbon::parse($deploy->updated_at)->diffForHumans(),
                    $deploy->error_message ?: 'No deploy message',
                    $deploy->deploy_time ? sprintf('Deployed in %d minutes', (int) $deploy->deploy_time / 60) : null
                );
            }
        } else {
            echo 'No deploys yet...';
        }
        ?>
        </div>
        </div>
        <?php
    }

    public function registerRestRoutes()
    {
        register_rest_route('netlify', '/webhooks', array(
            array(
                'methods'  => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'saveEvent'),
            )
        ));
    }

    public function saveEvent()
    {
        global $wpdb;

        $request = Request::createFromGlobals();
        $body = $request->getContent();

        if (!$this->signed($request, $body)) {
            return new WP_Error('unauthorized', __('Unauthorized'), array('status' => 403));
        }

        $body = json_decode($body);

        if (!is_object($body) || empty($body->id)) {
            return new WP_Error('invalid_payload', __('Invalid webhook payload.', 'wp-netlify-deploy'), array('status' => 400));
        }

        $data = [
            'deploy_id' => $body->id,
            'site_id' => $body->site_id,
            'build_id' => $body->build_id,
            'state' => $body->state,
            'name' => $body->name,
            'url' => $body->url,
            'ssl_url' => $body->ssl_url,
            'admin_url' => $body->admin_url,
            'deploy_url' => $body->deploy_url,
            'deploy_ssl_url' => $body->deploy_ssl_url,
            'created_at' => $body->created_at,
            'updated_at' => $body->updated_at,
            'user_id' => $body->user_id,
            'error_message' => $body->error_message,
            'branch' => $body->branch,
            'published_at' => $body->published_at,
            'context' => $body->context,
            'deploy_time' => $body->deploy_time,
            // 'summary' => (array) $body->summary,
            'screenshot_url' => $body->screenshot_url
        ];

        $table_name = $wpdb->prefix . 'netlify_deploys';
        $deploy_id = (string) $body->id;

        if (null === $wpdb->get_row($wpdb->prepare("SELECT 1 FROM {$table_name} WHERE deploy_id = %s LIMIT 1", $deploy_id))) {
            $wpdb->insert($table_name, $data);
        } else {
            $wpdb->update($table_name, $data, ['deploy_id' => $deploy_id]);
        }
    }

    public function signed(Request $request, string $body)
    {
        $signature = $request->headers->get('X-Webhook-Signature');

        if (!$signature) {
            error_log('No webhook signature');
            return false;
        }

        try {
            $decoded = JWT::decode($signature, new Key($_ENV['NETLIFY_JWS_TOKEN'], 'HS256'));
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }

        if ($decoded->sha256 != hash('sha256', $body)) {
            error_log("Hashes don't match");
            return false;
        }

        return true;
    }

    public function createDeploysTable()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'netlify_deploys';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            deploy_id varchar(24) NOT NULL,
            site_id varchar(36) NOT NULL,
            build_id varchar(24) NOT NULL,
            state varchar(20) NOT NULL,
            name varchar(200) NOT NULL,
            url varchar(255) NOT NULL,
            ssl_url varchar(255) NOT NULL,
            admin_url varchar(255) NOT NULL,
            deploy_url varchar(255) NOT NULL,
            deploy_ssl_url varchar(255) NOT NULL,
            created_at timestamp DEFAULT NULL,
            updated_at timestamp DEFAULT NULL,
            user_id varchar(24) NOT NULL,
            error_message text,
            branch varchar(255),
            published_at timestamp DEFAULT NULL,
            context varchar(255),
            deploy_time int UNSIGNED,
            summary longtext,
            screenshot_url varchar(255),
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
