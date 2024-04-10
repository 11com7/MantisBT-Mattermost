<?php
/**
 * Mattermost Integration
 * Copyright (C) AA'LA Solutions (info@aalasolutions.com)
 *
 * Mattermost Integration is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * Mattermost Integration is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Mattermost Integration; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 * or see http://www.gnu.org/licenses/.
 */

class MattermostPlugin extends MantisPlugin
{
    private $skip = false;

    public function register() {
        $this->name        = plugin_lang_get('title');
        $this->description = plugin_lang_get('description');
        $this->page        = 'config_page';
        $this->version     = '1.0';
        $this->requires    = ['MantisCore' => '2.5.x'];
        $this->author      = 'AA\'LA Solutions';
        $this->contact     = 'info@aalasolutions.com';
        $this->url         = 'https://aalasolutions.com';
    }

    public function install() {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            plugin_error(ERROR_PHP_VERSION, ERROR);
            return false;
        }
        if (!extension_loaded('curl')) {
            plugin_error(ERROR_NO_CURL, ERROR);
            return false;
        }
        return true;
    }

    public function config() {
        return [
            'url_webhooks' => [],
            'url_webhook' => '',
            'bot_name' => 'mantis',
            'bot_icon' => '',
            'skip_bulk' => true,
            'link_names' => false,
            'channels' => [],
            'default_channel' => '#general',
            'usernames' => [],
            'columns' => ['status', 'priority', 'handler_id', 'reporter_id', 'target_version', 'severity', 'description'],
        ];
    }

    public function hooks() {
        return [
            'EVENT_REPORT_BUG' => 'bug_report',
            'EVENT_UPDATE_BUG' => 'bug_update',
            'EVENT_BUG_DELETED' => 'bug_deleted',
            'EVENT_BUG_ACTION' => 'bug_action',
            'EVENT_BUGNOTE_ADD' => 'bugnote_add_edit',
            'EVENT_BUGNOTE_EDIT' => 'bugnote_add_edit',
            'EVENT_BUGNOTE_DELETED' => 'bugnote_deleted',
            'EVENT_BUGNOTE_ADD_FORM' => 'bugnote_add_form',
        ];
    }

    public function bugnote_add_form($event, $bug_id) {
        if ($_SERVER['PHP_SELF'] !== '/bug_update_page.php') {
            return;
        }

        echo '<tr>';
        echo '<th class="category">' . plugin_lang_get('skip') . '</th>';
        echo '<td colspan="5">';
        echo '<label>';
        echo '<input ', helper_get_tab_index(), ' name="mattermost_skip" class="ace" type="checkbox" />';
        echo '<span class="lbl"></span>';
        echo '</label>';
        echo '</td></tr>';
    }

    public function bug_report_update($event, $bug, $bug_id) {
        $this->skip = $this->skip || gpc_get_bool('mattermost_skip') || $bug->view_state == VS_PRIVATE;

        $project  = project_get_name($bug->project_id);
        $url      = string_get_bug_view_url_with_fqdn($bug_id);
        $summary  = $this->format_summary($bug);
        $reporter = $this->get_user_name(auth_get_current_user_id());
        $msg      = sprintf(plugin_lang_get($event === 'EVENT_REPORT_BUG' ? 'bug_created' : 'bug_updated'), $project,
                            $reporter, $url, $summary);
        $this->notify($msg, $this->get_webhook($project), $this->get_channel($project), $this->get_attachment($bug));
    }

    public function bug_report($event, $bug, $bug_id) {
        $this->bug_report_update($event, $bug, $bug_id);
    }

    public function bug_update($event, $bug_existing, $bug_updated) {
        $this->bug_report_update($event, $bug_updated, $bug_updated->id);
    }

    public function bug_action($event, $action, $bug_id) {
        $this->skip = $this->skip || gpc_get_bool('mattermost_skip') || plugin_config_get('skip_bulk');

        if ($action !== 'DELETE') {
            $bug = bug_get($bug_id);
            $this->bug_report_update('EVENT_UPDATE_BUG', $bug, $bug_id);
        }
    }

    public function bug_deleted($event, $bug_id) {
        $bug = bug_get($bug_id);

        $this->skip = $this->skip || gpc_get_bool('mattermost_skip') || $bug->view_state == VS_PRIVATE;

        $project  = project_get_name($bug->project_id);
        $reporter = $this->get_user_name(auth_get_current_user_id());
        $summary  = $this->format_summary($bug);
        $msg      = sprintf(plugin_lang_get('bug_deleted'), $project, $reporter, $summary);
        $this->notify($msg, $this->get_webhook($project), $this->get_channel($project));
    }

    public function bugnote_add_edit($event, $bug_id, $bugnote_id) {
        $bug     = bug_get($bug_id);
        $bugnote = bugnote_get($bugnote_id);

        $this->skip = $this->skip || gpc_get_bool('mattermost_skip') || $bug->view_state == VS_PRIVATE || $bugnote->view_state == VS_PRIVATE;

        $url      = string_get_bugnote_view_url_with_fqdn($bug_id, $bugnote_id);
        $project  = project_get_name($bug->project_id);
        $summary  = $this->format_summary($bug);
        $reporter = $this->get_user_name(auth_get_current_user_id());
        $note     = bugnote_get_text($bugnote_id);
        $msg      = sprintf(plugin_lang_get($event === 'EVENT_BUGNOTE_ADD' ? 'bugnote_created' : 'bugnote_updated'),
                            $project, $reporter, $url, $summary);
        $this->notify($msg, $this->get_webhook($project), $this->get_channel($project),
                      $this->get_text_attachment($this->bbcode_to_mattermost($note)));
    }

    public function get_text_attachment($text) {
        $attachment = ['color' => '#3AA3E3', 'mrkdwn_in' => ['pretext', 'text', 'fields']];
        $attachment['fallback'] = \TEXT."\n";
        $attachment['text']     = $text;
        return $attachment;
    }

    public function bugnote_deleted($event, $bug_id, $bugnote_id) {
        $bug     = bug_get($bug_id);
        $bugnote = bugnote_get($bugnote_id);

        $this->skip = $this->skip || gpc_get_bool('mattermost_skip') || $bug->view_state == VS_PRIVATE || $bugnote->view_state == VS_PRIVATE;

        $project  = project_get_name($bug->project_id);
        $url      = string_get_bug_view_url_with_fqdn($bug_id);
        $summary  = $this->format_summary($bug);
        $reporter = $this->get_user_name(auth_get_current_user_id());
        $msg      = sprintf(plugin_lang_get('bugnote_deleted'), $project, $reporter, $url, $summary);
        $this->notify($msg, $this->get_webhook($project), $this->get_channel($project));
    }

    public function format_summary($bug) {
        $summary = bug_format_id($bug->id) . ': ' . string_display_line_links($bug->summary);
        return strip_tags(html_entity_decode($summary));
    }

    public function format_text($bug, $text) {
        $t = string_display_line_links($this->bbcode_to_mattermost($text));

        return strip_tags(html_entity_decode((string) $t));
    }

    public function get_attachment($bug) {
        $attachment = ['fallback' => '', 'color' => '#3AA3E3', 'mrkdwn_in' => ['pretext', 'text', 'fields']];
        $t_columns  = (array)plugin_config_get('columns');
        foreach ($t_columns as $t_column) {
            $title = column_get_title($t_column);
            $value = $this->format_value($bug, $t_column);

            if ($title && $value) {
                $attachment['fallback'] .= $title . ': ' . $value . "\n";
                $attachment['fields'][] = ['title' => $title, 'value' => $value, 'short' => !column_is_extended($t_column)];
            }
        }
        return $attachment;
    }

    public function format_value($bug, $field_name) {
        $self   = $this;
        $values = [
            'id' => fn($bug) => sprintf('<%s|%s>', string_get_bug_view_url_with_fqdn($bug->id), $bug->id),
            'project_id' => fn($bug) => project_get_name($bug->project_id),
            'reporter_id' => fn($bug) => $this->get_user_name($bug->reporter_id),
            'handler_id' => fn($bug) => empty($bug->handler_id) ? plugin_lang_get('no_user') : $this->get_user_name($bug->handler_id),
            'duplicate_id' => fn($bug) => sprintf('<%s|%s>', string_get_bug_view_url_with_fqdn($bug->duplicate_id), $bug->duplicate_id),
            'priority' => fn($bug) => get_enum_element('priority', $bug->priority),
            'severity' => fn($bug) => get_enum_element('severity', $bug->severity),
            'reproducibility' => fn($bug) => get_enum_element('reproducibility', $bug->reproducibility),
            'status' => fn($bug) => get_enum_element('status', $bug->status),
            'resolution' => fn($bug) => get_enum_element('resolution', $bug->resolution),
            'projection' => fn($bug) => get_enum_element('projection', $bug->projection),
            'category_id' => fn($bug) => category_full_name($bug->category_id, false),
            'eta' => fn($bug) => get_enum_element('eta', $bug->eta),
            'view_state' => fn($bug) => $bug->view_state == VS_PRIVATE ? lang_get('private') : lang_get('public'),
            'sponsorship_total' => fn($bug) => sponsorship_format_amount($bug->sponsorship_total),
            'os' => fn($bug) => $bug->os,
            'os_build' => fn($bug) => $bug->os_build,
            'platform' => fn($bug) => $bug->platform,
            'version' => fn($bug) => $bug->version,
            'fixed_in_version' => fn($bug) => $bug->fixed_in_version,
            'target_version' => fn($bug) => $bug->target_version,
            'build' => fn($bug) => $bug->build,
            'summary' => fn($bug) => $self->format_summary($bug),
            'last_updated' => fn($bug) => date(config_get('short_date_format'), $bug->last_updated),
            'date_submitted' => fn($bug) => date(config_get('short_date_format'), $bug->date_submitted),
            'due_date' => fn($bug) => date(config_get('short_date_format'), $bug->due_date),
            'description' => fn($bug) => $self->format_text($bug, $bug->description),
            'steps_to_reproduce' => fn($bug) => $self->format_text($bug, $bug->steps_to_reproduce),
            'additional_information' => fn($bug) => $self->format_text($bug, $bug->additional_information),
        ];
        // Discover custom fields.
        $t_related_custom_field_ids = custom_field_get_linked_ids($bug->project_id);
        foreach ($t_related_custom_field_ids as $t_id) {
            $t_def                              = custom_field_get_definition($t_id);
            $values['custom_'.$t_def['name']] = fn($bug) => custom_field_get_value($t_id, $bug->id);
        }
        if (isset($values[$field_name])) {
            $func = $values[$field_name];
            return $func($bug);
        } else {
            return FALSE;
        }
    }

    public function get_channel($project) {
        $channels = plugin_config_get('channels');
        return array_key_exists($project, $channels) ? $channels[$project] : plugin_config_get('default_channel');
    }

    public function get_webhook($project) {
        $webhooks = plugin_config_get('url_webhooks');
        return array_key_exists($project, $webhooks) ? $webhooks[$project] : plugin_config_get('url_webhook');
    }

    public function notify($msg, $webhook, $channel, $attachment = FALSE) {
        if ($this->skip) {
            return;
        }
        if (empty($channel)) {
            return;
        }
        if (empty($webhook)) {
            return;
        }

        $url = sprintf('%s', trim((string) $webhook));

        $payload = [
            'channel' => $channel,
            'username' => plugin_config_get('bot_name'),
            'text' => $msg,
            'link_names' => plugin_config_get('link_names'),
        ];

        $bot_icon = trim((string) plugin_config_get('bot_icon'));

        if (empty($bot_icon)) {
            $payload['icon_url'] = 'https://github.com/aalasolutions/MantisBT-Mattermost/blob/master/mantis_logo.png?raw=true';
        } elseif (preg_match('/^:[a-z0-9_\-]+:$/i', $bot_icon)) {
            $payload['icon_emoji'] = $bot_icon;
        } elseif ($bot_icon) {
            $payload['icon_url'] = trim($bot_icon);
        }

        if ($attachment) {
            $payload['attachments'] = [$attachment];
        }

        $data = json_encode($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        if ($result !== 'ok') {
            trigger_error(curl_errno($ch) . ': ' . curl_error($ch), E_USER_WARNING);
            plugin_error('ERROR_CURL', E_USER_ERROR);
        }
        curl_close($ch);
    }

    public function bbcode_to_mattermost($bbtext) {
        $bbtags = [
            '[b]' => '*',
            '[/b]' => '* ',
            '[i]' => '_',
            '[/i]' => '_ ',
            '[u]' => '_',
            '[/u]' => '_ ',
            '[s]' => '~',
            '[/s]' => '~ ',
            '[sup]' => '',
            '[/sup]' => '',
            '[sub]' => '',
            '[/sub]' => '',

            '[list]' => '',
            '[/list]' => "\n",
            '[*]' => '• ',

            '[hr]' => "\n———\n",

            '[left]' => '',
            '[/left]' => '',
            '[right]' => '',
            '[/right]' => '',
            '[center]' => '',
            '[/center]' => '',
            '[justify]' => '',
            '[/justify]' => '',
        ];

        $bbtext = str_ireplace(array_keys($bbtags), array_values($bbtags), (string) $bbtext);

        $bbextended = [
            "/\[code(.*?)\](.*?)\[\/code\]/is" => "```$2```",
            "/\[color(.*?)\](.*?)\[\/color\]/is" => "$2",
            "/\[size=(.*?)\](.*?)\[\/size\]/is" => "$2",
            "/\[highlight(.*?)\](.*?)\[\/highlight\]/is" => "$2",
            "/\[url](.*?)\[\/url]/i" => "<$1>",
            "/\[url=(.*?)\](.*?)\[\/url\]/i" => "<$1|$2>",
            "/\[email=(.*?)\](.*?)\[\/email\]/i" => "<mailto:$1|$2>",
            "/\[img\]([^[]*)\[\/img\]/i" => "<$1>",
        ];

        foreach ($bbextended as $match => $replacement) {
            $bbtext = preg_replace($match, $replacement, $bbtext);
        }
        $bbtext = preg_replace_callback("/\[quote(=)?(.*?)\](.*?)\[\/quote\]/is", function ($matches) {
            if (!empty($matches[2])) {
                $result = "\n> _*" . $matches[2] . "* wrote:_\n> \n";
            }
            $lines = explode("\n", $matches[3]);
            foreach ($lines as $line) {
                $result .= "> " . $line . "\n";
            }
            return $result;
        }, $bbtext);
        return $bbtext;
    }

    public function get_user_name($user_id) {
        $user      = user_get_row($user_id);
        $username  = $user['username'];
        $usernames = plugin_config_get('usernames');
        $username  = array_key_exists($username, $usernames) ? $usernames[$username] : $username;
        return '@' . $username;
    }
}
