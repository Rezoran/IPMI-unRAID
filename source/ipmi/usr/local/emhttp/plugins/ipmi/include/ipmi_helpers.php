<?
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_options.php';
require_once '/usr/local/emhttp/plugins/ipmi/include/ipmi_drives.php';
require_once '/usr/local/emhttp/plugins/dynamix/include/Helpers.php';

$action = array_key_exists('action', $_GET) ? htmlspecialchars($_GET['action']) : '';
$hdd_temp = get_highest_temp();
extract(parse_plugin_cfg('dynamix',true));
if (isset($display['unit'])) $display_unit = $display['unit']; else $display_unit = "C";

if (!empty($action)) {
    $state = ['Critical' => 'red', 'Warning' => 'yellow', 'Nominal' => 'green', 'N/A' => 'blue'];
    if ($action === 'ipmisensors'){
        $return  = ['Sensors' => ipmi_sensors($ignore),'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmievents'){
        $return  = ['Events' => ipmi_events(),'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmiarch'){
        $return  = ['Archives' => ipmi_events(true), 'Network' => ($netsvc === 'enable'), 'State' => $state];
        echo json_encode($return);
    }
    elseif($action === 'ipmidash') {
        $return  = ['Sensors' => ipmi_sensors($dignore), 'Network' => ($netsvc === 'enable'),'State' => $state];
        echo json_encode($return);
    }
}

/* get highest temp of hard drives */
function get_highest_temp(){
    global $devignore;
    $ignore = array_flip(explode(',', $devignore));

    //get UA devices
    $ua_json = '/var/state/unassigned.devices/hdd_temp.json';
    $ua_devs = file_exists($ua_json) ? json_decode(file_get_contents($ua_json), true) : [];

    //get all hard drives
    $hdds = array_merge(parse_ini_file('/var/local/emhttp/disks.ini',true), parse_ini_file('/var/local/emhttp/devs.ini',true));

    $highest_temp = 0;
    foreach ($hdds as $hdd) {
        if (!array_key_exists($hdd['id'], $ignore)) {

            if(array_key_exists('temp', $hdd))
                $temp = $hdd['temp'];
            else{
                $ua_key = "/dev/".$hdd['device'];
                $temp = (array_key_exists($ua_key, $ua_devs)) ? $ua_devs[$ua_key]['temp'] : 'N/A';
            }

            if(is_numeric($temp))
                $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
        }
    }
    $return = ($highest_temp === 0) ? 'N/A': $highest_temp;
    return $return;
}

/* get current power from ipmi-dcmi */
function ipmi_current_power() {
    global $netopts;
    $cmd = "/usr/sbin/ipmi-dcmi --get-system-power-statistics $netopts 2>/dev/null";
    $return_var=null;
    exec($cmd, $output, $return_var);
    if ($return_var)
        return "N/A";
    foreach($output as $line){
        // one $line contains "Current Power                        : 119 Watts"
        if (preg_match("/Current Power\s*:\s*(\d+)\s*Watts/", $line, $matches)){
            return $matches[1];
        }
    }
    return "N/A";
}

/* get an array of all sensors and their values */
function ipmi_sensors($ignore='') {
    global $ipmi, $netopts, $hdd_temp;

    // return empty array if no ipmi detected and no network options
    if(!($ipmi || !empty($netopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : '-R '.escapeshellarg($ignore);
    $cmd = '/usr/sbin/ipmi-sensors --output-sensor-thresholds --comma-separated-output '.
        "--output-sensor-state --no-header-output --interpret-oem-data $netopts $ignored 2>/dev/null";
    $return_var=null ;    
    exec($cmd, $output, $return_var);

    // return empty array if error
    if ($return_var)
        return [];

    // add highest hard drive temp sensor and check if hdd is ignored
    $hdd = (preg_match('/99/', $ignore)) ? '' :
        "99,HDD Temperature,Temperature,Nominal,$hdd_temp,C,N/A,N/A,N/A,45.00,50.00,N/A,Ok";
    if(!empty($hdd)){
        if(!empty($netopts))
            $hdd = '127.0.0.1:'.$hdd;
        $output[] = $hdd;
    }
    // add power output from ipmi-dcmi if not ignored or already exists
    // for IDs see https://forums.unraid.net/topic/135823-plugin-ipmi-for-611/page/15/#comment-1376117
    if (!preg_match("/56/", $ignore) and !preg_grep("/56/", $output)){
        $curr_pwr = ipmi_current_power();
        $pwr = (preg_match('/56/', $ignore)) ? '' :
            "56,PSU1 Power In,Power Supply,Nominal,$curr_pwr,W,N/A,N/A,N/A,N/A,N/A,N/A,OK";
        if(!empty($pwr)){
            if(!empty($netopts))
                $pwr = '127.0.0.1:'.$pwr;
            $output[] = $pwr;
        }
    }
    // test sensor
    // $output[] = "98,CPU Temp,OEM Reserved,Nominal,N/A,N/A,N/A,N/A,N/A,45.00,50.00,N/A,'Medium'";

    // key names for ipmi sensors output
    $keys = ['ID','Name','Type','State','Reading','Units','LowerNR','LowerC','LowerNC','UpperNC','UpperC','UpperNR','Event'];
    $sensors = [];

    foreach($output as $line){

        $sensor_raw = explode(",", str_replace("'",'',$line));
        $size_raw = sizeof($sensor_raw);

        // add sensor keys as keys to ipmi sensor output
        $sensor = ($size_raw < 13) ? []: array_combine($keys, array_slice($sensor_raw,0,13,true));

        if(empty($netopts))
            $sensors[$sensor['ID']] = $sensor;
        else{

            //split id into host and id
            $id = explode(':',$sensor['ID']);
            $sensor['IP'] = trim($id[0]);
            $sensor['ID'] = trim($id[1]);
            if ($sensor['IP'] === 'localhost')
                $sensor['IP'] = '127.0.0.1';

            // add sensor to array of sensors
            $sensors[ip2long($sensor['IP']).'_'.$sensor['ID']] = $sensor;
        }
    }
    return $sensors;
}

/* get array of events and their values */
function ipmi_events($archive=null){
    global $ipmi, $netopts;
    $return_var = null;
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    if($archive) {
        $filename = "/boot/config/plugins/ipmi/archived_events.log";
        $output = is_file($filename) ? file($filename, FILE_IGNORE_NEW_LINES) : [] ;
    } else {
        $cmd = '/usr/sbin/ipmi-sel --comma-separated-output --output-event-state --no-header-output '.
            "--interpret-oem-data --output-oem-event-strings $netopts 2>/dev/null";
        $return_var=null ;
        exec($cmd, $output, $return_var);
    }

    // return empty array if error
    if ($return_var)
        return [];

    // key names for ipmi event output
    $keys = ['ID','Date','Time','Name','Type','State','Event'];
    $events = [];

    foreach($output as $line){

        $event_raw = explode(",", $line);
        $size_raw = sizeof($event_raw);

        // add event keys as keys to ipmi event output
        $event = ($size_raw < 7) ? []: array_combine($keys, array_slice($event_raw,0,7,true));

        // put time in sortable format and add unix timestamp
        $timestamp = $event['Date']." ".$event['Time'];
        if(strtotime($timestamp)) {
            if($date = Datetime::createFromFormat('M-d-Y H:i:s', $timestamp)) {
                $event['Date'] = $date->format('Y-m-d H:i:s');
                $event['Time'] = $date->format('U');
            }
        }

        if (empty($netopts)){

            if($archive)
                $events[$event['Time']."-".$event['ID']] = $event;
            else
                $events[$event['ID']] = $event;

        }else{

            //split id into host and id
            $id = explode(':',$event['ID']);
            $event['IP'] = trim($id[0]);
            if($archive)
                $event['ID'] = $event['Time'];
            else
                $event['ID'] = trim($id[1]);
            if ($event['IP'] === 'localhost')
                $event['IP'] = '127.0.0.1';

            // add event to array of events
            $events[ip2long($event['IP']).'_'.$event['ID']] = $event;
        }
    }
    return $events;
}

/* get select options for a fan and temp sensors */
function ipmi_get_options($selected=null){
    global $sensors;
    $options = "";
    foreach($sensors as $id => $sensor){
        $name = $sensor['Name'];
        $reading  = ($sensor['Type'] === 'OEM Reserved') ? $sensor['Event'] : $sensor['Reading'];
        $ip       = (empty($sensor['IP'])) ? '' : " ({$sensor['IP']})";
        $units    = is_numeric($reading) ? $sensor['Units'] : '';
        $options .= "<option value='$id'";

        // set saved option as selected
        if ($selected == $id)
            $options .= " selected";
        if ($sensor['Type'] == "Temperature")  $options .= ">$name$ip - ".my_temp($reading)."</option>"; else $options .= ">$name$ip - $reading $units</option>" ;
    }
    return $options;
}

/* get select options for enabled sensors */
function ipmi_get_enabled($ignore){
    global $ipmi, $netopts, $allsensors;
    $options = "";
    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($netopts)))
        return [];

    // create array of keyed ignored sensors
    $ignored = array_flip(explode(',', $ignore));
    foreach($allsensors as $sensor){
        $id       = $sensor['ID'];
        $reading  = $sensor['Reading'];
        $units    = ($reading === 'N/A') ? '' : " {$sensor['Units']}";
        $ip       = (empty($netopts))    ? '' : " {$sensor['IP']}";
        $options .= "<option value='$id'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($id, $ignored) ?  '' : " selected";

        $options .= ">{$sensor['Name']}$ip - $reading$units</option>";

    }
    return $options;
}

// get a json array of the contents of gihub repo
function get_content_from_github($repo, $file) {
    $ch = curl_init();
    $ch_vers = curl_version();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/'.$ch_vers['version']);
    curl_setopt($ch, CURLOPT_URL, $repo);
    $content = curl_exec($ch);
    curl_close($ch);
    if (!empty($content) && (!is_file($file) || $content != file_get_contents($file)))
        file_put_contents($file, $content);
}


/* FAN HELPERS */


/* get fan and temp sensors array */
function ipmi_fan_sensors($ignore=null) {
    global $ipmi, $fanopts, $hdd_temp;

    // return empty array if no ipmi detected or network options
    if(!($ipmi || !empty($fanopts)))
        return [];

    $ignored = (empty($ignore)) ? '' : "-R $ignore";
    $cmd = "/usr/sbin/ipmi-sensors --comma-separated-output --no-header-output --interpret-oem-data $fanopts $ignored 2>/dev/null";
    $return_var=null ;
    exec($cmd, $output, $return_var);

    if ($return_var)
        return []; // return empty array if error

    // add highest hard drive temp sensor
    $output[] = "99,HDD Temperature,Temperature, $hdd_temp,C,Ok";
    // test sensors
    //$output[] = "700,CPU_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "701,CPU_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "702,SYS_FAN1,Fan,1200,RPM,Ok";
    //$output[] = "703,SYS_FAN2,Fan,1200,RPM,Ok";
    //$output[] = "704,SYS_FAN3,Fan,1200,RPM,Ok";

    // key names for ipmi sensors output
    $keys = ['ID', 'Name', 'Type', 'Reading', 'Units', 'Event'];
    $sensors = [];

    foreach($output as $line){

        // add sensor keys as keys to ipmi sensor output
        $sensor_raw = explode(",", $line);
        $size_raw = sizeof($sensor_raw);
        $sensor = ($size_raw < 6) ? []: array_combine($keys, array_slice($sensor_raw,0,6,true));

        if ($sensor['Type'] === 'Temperature' || $sensor['Type'] === 'Fan')
            $sensors[$sensor['ID']] = $sensor;
    }
    return $sensors; // sensor readings
    unset($sensors);
}

/* get all fan options for fan control */
function get_fanctrl_options(){
    global $fansensors, $fancfg, $board, $board_json, $board_file_status, $board_status, $cmd_count, $range, $display_unit;
    if($board_status) {
        $i = 0;
        $fan1234 = 0;
        $sysfan = 0;
        $cpufan = 0;
        foreach($fansensors as $id => $fan){
            if($i > 11) break;
            if ($fan['Type'] === 'Fan'){
                $name    = htmlspecialchars($fan['Name']);
                $display = $name;
                if($board === 'Supermicro'){
                    $syscpu = false;
                    if(strpos ($name, 'SYS_FAN') !== false){
                        $syscpu = true;
                        $i++;
                        if($sysfan == 0){
                            $name = 'FANA';
                            $display = 'SYS_FAN';
                            $sysfan++;
                        }else{
                            continue;
                        }
                    }elseif(strpos ($name, 'CPU_FAN') !== false){
                        $syscpu = true;
                        $i++;
                        if($cpufan == 0){
                            $name = 'FAN1234';
                            $display = 'CPU_FAN';
                            $cpufan++;
                        }else{
                            continue;
                        }
                    }elseif($name !== 'FANA' && !$syscpu) {
                        #$i++;
                        if($fan1234 == 0){
                            $name = 'FAN1234';
                            $display = 'FAN1234';
                            $fan1234++;
                        }else{
                            continue;
                        }
                    }
                }
                if($board ==='Dell'){
                    $i++;
                    if($fan1234 == 0){
                    $name = 'FAN123456';
                    $display = 'FAN123456';
                    $fan1234++;}                        
                    else{
                        continue;}
                }
                $tempid  = 'TEMP_'.$name;
                $temphdd  = 'TEMPHDD_'.$name;
                $temp    = $fansensors[$fancfg[$tempid]];
                $temphddd    = $fansensors[$fancfg[$temphdd]];
                $templo  = 'TEMPLO_'.$name;
                $temphi  = 'TEMPHI_'.$name;
                $fanmax  = 'FANMAX_'.$name;
                $fanmin  = 'FANMIN_'.$name;
                $temploo  = 'TEMPLOO_'.$name;
                $temphio  = 'TEMPHIO_'.$name;
                $fanmaxo  = 'FANMAXO_'.$name;
                $fanmino  = 'FANMINO_'.$name;

                // hidden fan id
                echo '<input type="hidden" name="FAN_',$name,'" value="',$id,'"/>';

                // fan name: reading => temp name: reading
                echo '<dl><dt>',$display,' (',floatval($fan['Reading']),' ',$fan['Units'],'):</dt><span class="fanctrl-basic">';
                if ($temp['Name']){
                    echo $temp['Name'],' ('.my_temp(floatval($temp['Reading'])),' ','), ',
                    $fancfg[$templo],', ',$fancfg[$temphi],', ',number_format((intval(intval($fancfg[$fanmin])/$range*1000)/10),1),'-',number_format((intval(intval($fancfg[$fanmax])/$range*1000)/10),1),'%';
                }else{
                    echo 'Auto';
                }
                
                echo $display,' (',floatval($fan['Reading']),' ',$fan['Units'],'):';
                if (isset($temphddd['Name'])){
                    echo "&nbsp;&nbsp;&nbsp;&nbsp;Override:".$temphddd['Name'].' ('.my_temp(floatval($temp['Reading'])),' ','), ',
                    $fancfg[$temploo],', ',$fancfg[$temphio],', ',number_format((intval(intval($fancfg[$fanmino])/$range*1000)/10),1),'-',number_format((intval(intval($fancfg[$fanmaxo])/$range*1000)/10),1),'%';
                }else{
                    echo 'Not Defined';
                }
                echo '</span><span class="fanctrl-settings">&nbsp;</span>';

                // check if board.json exists then if fan name is in board.json
                $noconfig = '<font class="red"><b><i> (fan is not configured!)</i></b></font>';
                if($board_file_status){
                    if(!array_key_exists($name, $board_json[$board]['fans']))
                        if ($cmd_count !== 0){
                            if(!array_key_exists($name, $board_json["{$board}1"]['fans']))
                                echo $noconfig;
                        }else{
                            echo $noconfig;
                        }
                } else {
                    echo $noconfig;
                }

                echo '</dd></dl>';

                // temperature sensor
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>Temperature sensor:</dd></dl></dt><dd>',
                '<select name="',$tempid,'" class="fanctrl-temp fanctrl-settings">',
                '<option value="0">Auto</option>',
                get_temp_options($fancfg[$tempid]),
                '</select></dd></dl>';
                
                if ($fancfg[$tempid] == "99") $disabled = "" ; else $disabled = " disabled ";

                // high temperature threshold
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>High temperature threshold (&deg;'.$display_unit.'):</dd></dl></dt>',
                '<dd><select name="',$temphi,'" class="',$tempid,' fanctrl-settings">',
                get_temp_range('HI', $fancfg[$temphi],$display_unit),
                '</select></dd></dl>';

                // low temperature threshold
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>Low temperature threshold (&deg;'.$display_unit.'):</dd></dl></dt>',
                '<dd><select name="',$templo,'" class="',$tempid,' fanctrl-settings">',
                get_temp_range('LO', $fancfg[$templo],$display_unit),
                '</select></dd></dl>';

                // fan control maximum speed
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>Fan speed maximum (%):</dd></dl></dt><dd>',
                '<select name="',$fanmax,'" class="',$tempid,' fanctrl-settings">',
                get_minmax_options('HI', $fancfg[$fanmax]),
                '</select></dd></dl>';

                // fan control minimum speed
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>Fan speed minimum (%):</dd></dl></dt><dd>',
                '<select name="',$fanmin,'" class="',$tempid,' fanctrl-settings">',
                get_minmax_options('LO', $fancfg[$fanmin]),
                '</select></dd></dl>&nbsp;';
       
                // temperature sensor Spundown
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>HDD Spundown Temperature sensor:</dd></dl></dt><dd>',
                '<select', $disabled, ' name="', $temphdd, '" class="fanctrl-temp fanctrl-settings">',
                '<option value="0">None</option>',
                get_temp_options($fancfg[$temphdd]),
                '</select></dd></dl>';

                // Check if the Spundown values should be shown
                if ($fancfg[$temphdd] != 0) {
                // high temperature threshold Spundown
                echo '<dl class="fanctrl-settings">',
                '<dt><dl><dd>High temperature threshold Spundown (&deg;', $display_unit, '):</dd></dl></dt>',
                '<dd><select name="', $temphio, '" class="', $tempid, ' fanctrl-settings">',
                get_temp_range('HI', $fancfg[$temphio], $display_unit),
                '</select></dd></dl>';

                // low temperature threshold Spundown
               echo '<dl class="fanctrl-settings">',
               '<dt><dl><dd>Low temperature threshold Spundown (&deg;', $display_unit, '):</dd></dl></dt>',
               '<dd><select name="', $temploo, '" class="', $tempid, ' fanctrl-settings">',
               get_temp_range('LO', $fancfg[$temploo], $display_unit),
               '</select></dd></dl>';

               // fan control maximum speed Spundown
               echo '<dl class="fanctrl-settings">',
               '<dt><dl><dd>Fan speed maximum Spundown (%):</dd></dl></dt><dd>',
               '<select name="', $fanmaxo, '" class="', $tempid, ' fanctrl-settings">',
               get_minmax_options('HI', $fancfg[$fanmaxo]),
               '</select></dd></dl>';

              // fan control minimum speed Spundown
              echo '<dl class="fanctrl-settings">',
              '<dt><dl><dd>Fan speed minimum Spundown (%):</dd></dl></dt><dd>',
              '<select name="', $fanmino, '" class="', $tempid, ' fanctrl-settings">',
              get_minmax_options('LO', $fancfg[$fanmino]),
            '</select></dd></dl>&nbsp;';
            }

                $i++;
            }
        }
    } else {
        echo '<dl><dt>&nbsp;</dt><dd><p><b><font class="red">Your board is not currently supported</font></b></p></dd></dl>';
    }
}

/* get select options for temp & fan sensor types from fan ip*/
function get_temp_options($selected=0){
    global $fansensors, $fanip;
    $options = '';
    foreach($fansensors as $id => $sensor){
        if (($sensor['Type'] === 'Temperature') || ($sensor['Name'] === 'HDD Temperature')){
            $name = $sensor['Name'];
            $options .= "<option value='$id'";

            // set saved option as selected
            if (intval($selected) === $id)
                $options .= ' selected';

        $options .= ">$name</option>";
        }
    }
    return $options;
}

/* get options for high or low temp thresholds */
function get_temp_range($order, $selected=0,$unit = "C"){
    $temps = [20,80];
    if ($order === 'HI')
      rsort($temps);
    $options = "";
    foreach(range($temps[0], $temps[1], 5) as $temp){
        $options .= "<option value='$temp'";

        // set saved option as selected
        if (intval($selected) === $temp)
            $options .= " selected";
        if ($unit == "F") $temp=round(9/5*$temp)+32; ;
        $options .= ">$temp</option>";
    }
    return $options;
}

/* get options for fan speed min and max */
function get_minmax_options($order, $selected=0){
    global $range;
    $incr = [1,$range];
    if ($order === 'HI')
      rsort($incr);
    $options = "";
    foreach(range($incr[0], $incr[1], 1) as $value){
        $options .= "<option value='$value'";

        // set saved option as selected
        if (intval($selected) === $value)
            $options .= ' selected';

        $options .= '>'.number_format((intval(($value/$range)*1000)/10),1).'</option>';
    }
    return $options;
}

/* get network ip options for fan control */
function get_fanip_options(){
    global $ipaddr, $fanip;
    $options = "";
    $ips = 'None,'.$ipaddr;
    $ips = explode(',',$ips);
        foreach($ips as $ip){
            $options .= '<option value="'.$ip.'"';
            if($fanip === $ip)
                $options .= ' selected';

            $options .= '>'.$ip.'</option>';
        }
    echo $options;
}

function get_hdd_options($ignore=null) {
    $hdds = get_all_hdds();
    $ignored = array_flip(explode(',', $ignore));
    $options = "";
    foreach ($hdds as $serial => $hdd) {
        $options .= "<option value='$serial'";

        // search for id in array to not select ignored sensors
        $options .= array_key_exists($serial, $ignored) ?  '' : " selected";

        $options .= ">$serial ($hdd)</option>";

    }
    return $options;
}

?>
