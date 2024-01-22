<?php
// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ('abc' is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'wet_feditext';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 0;

$plugin['version'] = '0.3.1';
$plugin['author'] = 'Robert Wetzlmayr';
$plugin['author_uri'] = 'https://wetzlmayr.at/';
$plugin['description'] = 'Implements Fediverse protocols like Webfinger, Nodeinfo, and ActivityPub for Textpattern as sufficient yet privacy-aware stubs.';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
# $plugin['order'] = 5;

// Plugin 'type' defines where the plugin is loaded
// 0 = public       : only on the public side of the website (default)
// 1 = public+admin : on both the public and non-AJAX admin side
// 2 = library      : only when include_plugin() or require_plugin() is called
// 3 = admin        : only on the non-AJAX admin side
// 4 = admin+ajax   : only on admin side
// 5 = public+admin+ajax   : on both the public and admin side
$plugin['type'] = 1;

// Plugin 'flags' signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use.
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events
$plugin['flags'] = PLUGIN_HAS_PREFS | PLUGIN_LIFECYCLE_NOTIFY;

if (!defined('txpinterface'))
    @include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---

h3. Purpose

Implements Fediverse protocols like Webfinger, Nodeinfo, and ActivityPub for Textpattern.

h3. How to use

* Install.
* Edit the file /.well-known/.htaccess, if it exists, and comment out the lines according to the example file `.htaccess.dist` that comes with this plugin.
* Enable.
* Enjoy.

To verify that this plugin is working properly, please set the "production status of your site":./index.php?event=prefs to *Debugging* and visit the links below. They should produce readable results and not return a @404 Not Found@ error or worse.

* "$":/.well-known/webfinger?resource=acct:donald.swain@example.com
* "$":/.well-known/nodeinfo
* "$":/nodeinfo/2.1

User avatars are found by following these rules:

# "Upload a user avatar image":./?event=image and assign it to the 'user-avatar' "image category":./?event=category (you may need to create this category if it does not exist).
# Set the image name field to the user's login name.

User profile pages are found by following these rules:

# "Write an article":./?event=article and assign it to the  'user-profile' "article category":./?event=category (you may also need to create this category if it does not exist).
# Include the user's login name in the article's keywords list.

You may want to submit your site to the "FediDB(Fediverse Network Statistics)":https://fedidb.org/ using their "Add my instance" command to satisfy your inner bureaucrat.

h3. Licence

This plugin is released under the "Gnu General Public Licence":http://www.gnu.org/licenses/gpl.txt.

# --- END PLUGIN HELP ---
<?php
}

# --- BEGIN PLUGIN CODE ---

class wet_feditext
{
    protected $event = __CLASS__;

    function __construct()
    {
        // Hook lifecycle event
        if (txpinterface === 'admin') {
            register_callback("lifecycle", "plugin_lifecycle." . $this->event);
            add_privs($this->event, '1');
        }
        // Hook webfinger route
        new wet_webfinger;
        // Hook nodeinfo routes
        new wet_nodeinfo;
        // Hook ActivityPub routes
        new wet_activitypub;
    }

    static function lifecycle($event, $step)
    {
        if ($step == 'installed') {
            // Create acct => aliases table TBC

            /**
             * CREATE TABLE `wet_feditext` (
             * `name` VARCHAR(64) NOT NULL,
             * `alias` VARCHAR(255) NOT NULL,
             * INDEX `name` (`name`)
             * )
             * COLLATE='utf8mb4_unicode_ci';
             */
        }
    }
}

class wet_webfinger
{
    function __construct()
    {
        // Hook Webfinger route
        register_callback([__CLASS__, 'webfinger'], 'pretext_end');
    }

    /**
     * Respond to a webfinger request at its well-known URI `/.well-known/webfinger?resource=acct:username@domain`
     *
     * see https://docs.joinmastodon.org/spec/webfinger/
     * see https://datatracker.ietf.org/doc/html/rfc7033
     */

    static function webfinger()
    {
        global $pretext;
        global $prefs;
        global $production_status;

        // Well-known route requested?
        if (preg_match('%^/\.well-known/webfinger%i', $pretext['request_uri'])) {
            $acct = gps('resource');
            if (!preg_match('/^acct:(.*)@.*$/i', $acct, $matches)) {
                // Invalid acct: request parameter
                txp_die('<!-- Invalid acct:  ' . txp_escape(true, $acct) . ' -->', '404');
            }
            txp_status_header('200 OK');
            $username = $matches[1];
            $siteurl = $prefs['siteurl'];

            if ($production_status === 'debug') {
                // Start with a really well-known sample account just for testing. The next lines can be left as is or deleted - your choice.
                $known_accounts['donald.swain@example.com']['alias'] = ['@donald_swain@mastodon.social'];
                $known_accounts["$siteurl@$siteurl"]['alias'] = ['@donald_swain@mastodon.social'];
            }

            $acct = preg_replace('/^acct:/', '', $acct);

            // Collect a table of username => aliases relations
            // matching the requested acct: name from this plugins add-on DB table `wet_feditext`.
            $rs = safe_query(
            /**
             * SELECT CONCAT (txp_users.`name`, '@', 'example.com'), wet_feditext.alias
             * FROM txp_users
             * INNER JOIN txp_wet_feditext
             * ON txp_users.name = wet_feditext.name;
             * WHERE txp_users.name = $acct
             */
                "SELECT" .
                " CONCAT (txp_users.`name`, '@', '" . safe_escape($siteurl) . "') AS acct, wet_feditext.alias" .
                " FROM " . safe_pfx_j('txp_users') .
                " INNER JOIN " . safe_pfx_j('wet_feditext') .
                " ON txp_users.`name` = wet_feditext.`name`" .
                "WHERE txp_users.`name` = '" . safe_escape($username) . "'");

            // Collect persistent actor aliases for the site user given as the `acct:` request parameter.
            foreach ($rs as $row) {
                $known_accounts[$row['acct']]['alias'][] = $row['alias'];
            }

            // Respond with the found user's details
            if (isset($known_accounts[$acct]['alias']) && is_array($known_accounts[$acct]['alias'])) {

                $out = [
                    'subject' => "acct:$acct",
                    'aliases' => []
                ];

                foreach ($known_accounts[$acct]['alias'] as $alias) {
                    if (preg_match('/^@(.*)@(.*)$/', $alias, $matches)) {
                        $username = $matches[1];
                        $domain = $matches[2];
                    } else {
                        // Invalid alias in config DB table
                        txp_die('<!-- Invalid alias:  ' . txp_escape(true, $alias) . ' -->', '404');
                    }
                    header('Content-Type: application/jrd+json; charset=utf-8');

                    $out['aliases'][] = $alias;
                    // webfinger the users account
                    $profile_url = parse('<txp:article_custom match="user-profile" keywords="' . $username . '" limit="1"><txp:permlink /></txp:article_custom>');  // TODO

                    if (!empty($profile_url)) {
                        $out['links'][] = [
                            "rel" => "http://webfinger.net/rel/profile-page",
                            "type" => "text/html",
                            "href" => "$profile_url"
                        ];

                        $out['links'][] = [
                            "rel" => "self",
                            "type" => "application/activity+json",
                            "href" => "$profile_url.json"
                        ];
                    }

                    // webfinger the sites instance actor account
                    $out['links'][] = [
                        "rel" => "self",
                        "type" => "application/activity+json",
                        "href" => "https://$siteurl/@$siteurl/"
                    ];

                    if (false) { // Is this useful anyhow?
                        $out['links'][] = [
                            "rel" => "http://ostatus.org/schema/1.0/subscribe",
                            "template" => "https://$siteurl/authorize_interaction?uri={uri}"
                        ];
                    }

                    $avatar_url = parse('<txp:images category="user-avatar" name="' . $username . '" limit="1"><txp:image_url /></txp:images>');
                    $avatar_type = parse('<txp:images category="user-avatar" name="' . $username . '" limit="1"><txp:image_info type="ext"/></txp:images>');

                    $mime_types = [
                        '.gif' => 'image/gif',
                        '.jpg' => 'image/jpeg',
                        '.jpeg' => 'image/jpeg',
                        '.png' => 'image/png',
                        '.webp' => 'image/webp',
                        '.svg' => 'image/svg+xml',
                        '.avif' => 'image/avif',
                    ];

                    $avatar_type = isset($mime_types[$avatar_type])? $mime_types[$avatar_type] : 'image/wtf';

                    if (!empty($avatar_url)) {
                        $out['links'][] = [
                            "rel" => "http://webfinger.net/rel/avatar",
                            "type" => $avatar_type,
                            "href" => $avatar_url
                        ];
                    }

                    die(json_encode($out));
                }
            }
        }
    }
}

class wet_nodeinfo
{
    function __construct()
    {
        global $plugin;
        register_callback([__CLASS__, 'nodeinfo'], 'pretext_end');
        register_callback([__CLASS__, 'nodeinfo_2_1'], 'pretext_end');
    }

    /**
     * Respond to a nodeinfo request at its well-known URI `/.well-known/nodeinfo`
     * see http://nodeinfo.diaspora.software/protocol.html
     */
    static function nodeinfo()
    {
        global $pretext;

        // Well-known route requested?
        if (preg_match('%^/\.well-known/nodeinfo$%i', $pretext['request_uri'])) {
            header('Content-Type: application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.1#"; charset=utf-8');
            // Tell them to look into https://example.com/nodeinfo/2.1 for the actual nodeinfo data
            $response = json_encode([
                'links' => [
                    'rel' => "http://nodeinfo.diaspora.software/ns/schema/2.1",
                    'href' => parse('<txp:site_url />'). "nodeinfo/2.1"
                ]
            ]);
            die($response);
        }
    }

    /**
     * Give nodeinfo according to schema.
     * see http://nodeinfo.diaspora.software/docson/index.html#/ns/schema/2.1#$$expand
     */
    static function nodeinfo_2_1()
    {
        global $pretext;
        global $thisversion;
        global $txp_permissions;

        define('NOYB', true); // Set to `true`to hide site details not intended to be shown to the general public.
        // Prefix line with a comment if you want this.

        // Route for nodeinfo response requested?
        if (preg_match('%^/nodeinfo/2\.1$%i', $pretext['request_uri'])) {
            header('Content-Type: application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.1#"; charset=utf-8');
            $response = [
                'version' => '2.1',
                'software' => [
                    'name' => 'textpattern', // The schema says "pattern": "^[a-z0-9-]+$" thus only lower-case chars
                    'homepage' => 'https://textpattern.com/',
                    // Show major version number to meet schema requirements while maintaining "security by obscurity".
                    'version' => preg_split('/\./', $thisversion)[0]
                ],
                'protocols' => [
                    'activitypub'
                ],
                'services' => [
                    'inbound' => [
                        'atom1.0',
                        'rss2.0',
                    ],
                    'outbound' => [
                        'atom1.0',
                        'rss2.0',
                        'textpattern'
                    ]
                ],
                'openRegistrations' => false,
                'usage' => [
                    'users' => [
                        'total' => 1,
                    ]
                ]
            ];

            // Show them a few of our more private details if we are inclined to...
            if(!defined('NOYB')) {
                $response['software']['version'] = $thisversion;
                // Count total users who can at least publish articles
                $response['usage']['users']['total'] = (int) safe_count('txp_users', 'privs IN (' . $txp_permissions['article.publish'] . ')');
                $response['usage']['localPosts'] = (int) safe_count('textpattern', 'Status = ' . STATUS_LIVE);
            }
            die(json_encode($response));
        }
    }

}

class wet_activitypub
{
    function __construct()
    {
        // Hook ActivityPub routes
        register_callback([__CLASS__, 'activity'], 'pretext_end');
    }

    /**
     * Response to a `GET https://example.com/` request with header `Accept: application/activity+json`
     */
    static function activity()
    {
        global $prefs;
        global $pretext;

        $pretext ['accept'] = preg_split('/[\s,]+/', $_SERVER['HTTP_ACCEPT']);

        if (preg_match('%^/$%i', $pretext['request_uri']) &&
            in_array('application/activity+json', $pretext['accept'])) {
            header('Content-Type: application/activity+json; charset=utf-8');
            $response = json_encode([
                "@context" => [
                    "https://www.w3.org/ns/activitystreams",
                    "https://w3id.org/security/v1" => [
                        "manuallyApprovesFollowers" => "as:manuallyApprovesFollowers",
                        "PropertyValue" => "schema:PropertyValue",
                        "schema" => "http://schema.org#",
                        "pt" => "https://joinpeertube.org/ns#",
                        "toot" => "http://joinmastodon.org/ns#",
                        "webfinger" => "https://webfinger.net/#",
                        "litepub" => "http://litepub.social/ns#",
                        "lemmy" => "https://join-lemmy.org/ns#",
                        "value" => "schema:value",
                        "Hashtag" => "as:Hashtag",
                        "featured" => [
                            "@id" => "toot=>featured",
                            "@type" => "@id"
                        ]
                    ]
                ],
                "summary" => $prefs['site_slogan'],
                "url" => $prefs['siteurl'] . '@' . $prefs['siteurl']
                // TBC...
            ]);

            die($response);
        }
    }
}

new wet_feditext;
# --- END PLUGIN CODE ---
?>
