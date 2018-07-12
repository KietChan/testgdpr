<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/siwalikm/quick-form-css@2.2.2/qfc-light.css">
<style>
    h3 {
        color: blue;
        font-weight: 900;
        font-size: 28px;
    }
</style>

<?php
require_once("config.php");

$PAGE->set_context(context_system::instance());

require_login(null, false);

if($_SERVER["REQUEST_METHOD"] === "POST") {
    $component = $_POST["component"];
    $showdbdebug = empty($_POST["showdbdebug"]) ? false : true;
    $userid = $USER->id;
    $user = get_complete_user_data("id", $userid);


    $provider = get_component_class($component);

    $contextlist = $provider::get_contexts_for_userid($userid);
    $contextlistarray = $contextlist->get_contextids();

    $DB->set_debug(false);
    ?>
    <div class="qfc-container">
        <h2>GDPR Test</h2>
        <label>Testing plugin <b><?php echo $component ?></b> for user <b><?php echo $USER->username ?></b>, matching
            context:
            <b><?php echo(empty($contextlistarray) ? "None" : implode(", ", $contextlistarray)); ?></b>, refresh this
            page to update matched context.</label>
        <form method="POST">
            <input type="hidden" name="component" value="<?php echo $component ?>"/>
            <input type="hidden" name="execute" value="1"/>
            <div><input type="checkbox"
                        name="requestexport" <?php echo empty($_POST["requestexport"]) ? "" : "checked" ?> /> <label>Export
                    user data</label></div>
            <div><input type="checkbox"
                        name="requestdeleteuser" <?php echo empty($_POST["requestdeleteuser"]) ? "" : "checked" ?> />
                <label>Delete user data</label></div>
            <div><input type="checkbox"
                        name="requestdeletecontext" <?php echo empty($_POST["requestdeletecontext"]) ? "" : "checked" ?>/>
                <label>Delete data for context below:</label></div>
            <div>
                <div>
                    <label></label>
                    <select name="contexttodelete">
                        <option disabled selected value="0">Select context's ID to delete</option>
                        <?php
                        foreach ($contextlistarray as $item) {
                            echo "<option value='$item''>$item</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div><input type="checkbox"
                        name="showdbdebug" <?php echo empty($_POST['showdbdebug']) ? "" : "checked" ?> />
                <label>Show SQL query</label></div>
            <div>
                <button type="submit">Execute</button>
                <button type="button" onclick="window.location.href = window.location.href">Back</button>
            </div>
        </form>
    </div>

    <?php
    if(!empty($_POST['requestexport'])) {
        export_privacy_data($provider, $user, $component, $contextlistarray, $showdbdebug);
    }
    if(!empty($_POST['requestdeleteuser'])) {
        delete_user_data($provider, $user, $component, $contextlistarray, $showdbdebug);
    }
    if(!empty($_POST['requestdeletecontext'])) {
        delete_context_data($provider, $component, empty($_POST['contexttodelete']) ? false : $_POST['contexttodelete'], $showdbdebug);
    }
} else {?>
    <div class="qfc-container">
        <h2>GDPR Test</h2>
        <label>Test GDPR for user <b><?php echo $USER->username ?></b> by calling directly to function instead of running cron and create user again and again, class provider
            and function to get context must be implement before submit</label>
        <form method="POST">
            <div>
                <div>
                    <input name="component" placeholder="Component's name" required>
                </div>
                <div>
                    <button type="submit">Submit</button>
                </div>
            </div>
        </form>
    </div>
    <?php
}
?>

<?php
function get_component_class($componentName) {
    $class = '\\' . $componentName . '\privacy\provider';
    if (!class_exists($class)) {
        die("<p style='color: red'>Class <b>$class</b> not exist!</p>");
    }
    return new $class();
}

function export_privacy_data($provider, $user, $componentname,  array $contextlistarray, $showdbdebug) {
    global $CFG, $DB, $USER;
    echo "<h3>Export user data:</h3>";
    if(empty($contextlistarray)) {
        echo "No matching context to export, skip export user data.";
        return false;
    }

    if(!method_exists($provider, 'export_user_data')) {
        echo "Function \"export_user_data()\" is not implemented, skip export user data.";
        return false;
    }

    if($showdbdebug) {
        $DB->set_debug(true);
    }

    $approvedcontext = new \core_privacy\local\request\approved_contextlist($user, $componentname, $contextlistarray);
    $provider::export_user_data($approvedcontext);
    $DB->set_debug(false);

    ob_start();
    $manager = new \core_privacy\manager();
    $exportedcontent = $manager->export_user_data(new \core_privacy\local\request\contextlist_collection($user->id));
    ob_get_clean();

    $fs = get_file_storage();
    $filerecord = new \stdClass;
    $filerecord->component = 'tool_dataprivacy';
    $filerecord->contextid = context_user::instance($USER->id)->id;
    $filerecord->userid = $USER->id;
    $filerecord->filearea = 'export';
    $filerecord->filename = time() . generateRandomString(5) . '.zip';
    $filerecord->filepath = '/';
    $filerecord->itemid = '69696';
    $filerecord->license = $CFG->sitedefaultlicense;
    $filerecord->author = fullname($USER);
    $thing = $fs->create_file_from_pathname($filerecord, $exportedcontent);
    $downloadurl = moodle_url::make_pluginfile_url(context_user::instance($USER->id)->id, 'tool_dataprivacy', 'export', $thing->get_itemid(),
        $thing->get_filepath(), $thing->get_filename(), true);

    echo "<p>Click <a href='$downloadurl'>Here</a> to download exported file for component <b>$componentname</b> belong to <b>$user->username</b>.</p>";

    return true;
}

function delete_user_data($provider, $user,  $componentname, $contextlistarray, $showdbdebug) {
    global $DB;

    echo "<h3>Delete user data:</h3>";

    if (empty($contextlistarray)) {
        echo "No matching context found, skip delete user data.";
        return false;
    }

    if(!method_exists($provider, 'delete_data_for_user')) {
        echo "Function \"delete_data_for_user()\" is not implemented, skip delete user data.";
        return false;
    }

    $approvedcontext = new \core_privacy\local\request\approved_contextlist($user, $componentname, $contextlistarray);
    if($showdbdebug) {
        $DB->set_debug(true);
    }

    $provider::delete_data_for_user($approvedcontext);
    $DB->set_debug(false);

    $affectcontext = empty($contextlistarray) ? "None" : implode(', ', $contextlistarray);
    echo "$user->username</b> in component <b>$componentname</b>, affect context: <b>$affectcontext</b></p>";

    return true;
}

function delete_context_data($provider, $componentname, $contextid, $showdbdebug) {
    global $DB;
    echo "<h3>Delete context data:</h3>";
    if(empty($contextid)) {
        echo "<p>You must select the context id to delete, skip delete context data.</p>";
        return false;
    }

    if(!method_exists($provider, 'delete_data_for_all_users_in_context')) {
        echo "<p>Function \"delete_data_for_all_users_in_context()\" is not implemented, skip delete context data.</p>";
        return false;
    }
    if($showdbdebug) {
        $DB->set_debug(true);
    }

    $provider::delete_data_for_all_users_in_context(\context::instance_by_id($contextid));
    $DB->set_debug(false);

    echo "<p>Deleted all personal data belong to context <b>$contextid ($componentname)</b></p>";

    return true;
}

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

die();
