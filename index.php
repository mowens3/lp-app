<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015 to 2022 Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

include 'vendor/autoload.php';

use Seat\Eseye\Cache\NullCache;
use Seat\Eseye\Configuration;
use Seat\Eseye\Containers\EsiAuthentication;
use Seat\Eseye\Eseye;

// Disable all caching by setting the NullCache as the
// preferred cache handler. By default, Eseye will use the
// FileCache.
$configuration = Configuration::getInstance();
$configuration->cache = NullCache::class;


/**
 * @return string
 */
function get_sso_callback_url()
{

    if (getenv('SSL') == 'on')
        $protocol = 'https://';
    else
        $protocol = 'http://';

    return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?action=eveonlinecallback';
}


$_SESSION['clientid'] = getenv('clientid');
$_SESSION['secret'] = getenv('secret');
$_SESSION['state'] = uniqid();

// Generate the url with the requested scopes
$url = 'https://login.eveonline.com/v2/oauth/authorize/?response_type=code&redirect_uri=' .
    urlencode(get_sso_callback_url()) . '&client_id=' .
    $_SESSION['clientid'] . '&scope=esi-characters.read_notifications.v1&state='.$_SESSION['state'];

$login_url = '<a href="'.$url.'">Generate LP Report</a>';



switch ($_GET['action']) {

    // Display the form to create a new login.
    case 'new':
        print_r($login_url);
        break;

    case 'eveonlinecallback':
        // Verify the state.


        if ($_GET['state'] != $_REQUEST['state']) {

            echo 'Invalid State! You will have to start again!<br>';
            echo '<a href="' . $_SERVER['PHP_SELF'] . '?action=new">Start again</a>';
            exit;
        }


        
        // Clear the state value.
        $_SESSION['state'] = null;

        // Prep the authentication header.
        $headers = [
            'Authorization: Basic ' . base64_encode($_SESSION['clientid'] . ':' . $_SESSION['secret']),
            'Content-Type: application/x-www-form-urlencoded',
            'Host:login.eveonline.com'
        ];

        $url='https://login.eveonline.com/v2/oauth/token';


        $fields_string='';
        $fields=array(
                    'grant_type' => 'authorization_code',
                    'code' => $_GET['code'],
                 //   'code_verifier' => 'codeverifier'
                );
        foreach ($fields as $key => $value) {
            $fields_string .= $key.'='.$value.'&';
        }
        $fields_string = rtrim($fields_string, '&');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        ///curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, count($fields));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);

        $data = json_decode($result);

        
        $client_id = $_SESSION['clientid'];
        $secret_key = $_SESSION['secret'];

        if(isset($data->refresh_token)){
            $_SESSION['refresh_token'] = $data->refresh_token;
        }else{
            break;
        }

        if(isset($data->access_token)){
            $_SESSION['access_token'] = $data->access_token;
        }else{
            break;
        }




        $authentication = new \Seat\Eseye\Containers\EsiAuthentication([
            'client_id'     => $client_id,
            'secret'        => $secret_key,
            'access_token' => $_SESSION['access_token'],
            'refresh_token' => $_SESSION['refresh_token'],
        ]);
        ///Great. We instantiate a new Eseye instance with the authentication information as argument:

        $esi = new \Seat\Eseye\Eseye($authentication);


        $meta_info = $esi->invoke('get', '/verify');
        $meta_array = json_decode($meta_info->raw, true);


        $loyalty_info = $esi->invoke('get', '/characters/{character_id}/notifications/', [
            'character_id' => $meta_array['CharacterID'],
        ]);



        foreach($loyalty_info as $key=>$value){
            if($value->type == "FacWarLPPayoutEvent"){
                $first_line = preg_split('#\r?\n#', $value->text, 2)[0];
                $amount = (int)preg_replace('/[^0-9]/', '', $first_line);
                $date = strtotime($value->timestamp); 
                $array_day_date = date('d-M-Y', $date);
                $array_month_date = date('M-Y', $date);
                $this_list[] = ['y'=> $amount, 'label'=> $array_day_date];
                $this_day_list[$array_day_date] += $amount;
                $this_month_list[$array_month_date] += $amount;
            }
        }


        foreach($this_day_list as $key=>$value){
            $total_day_list[] = array('date'=>$key, 'total'=>$value);
        }


        foreach($this_month_list as $key=>$value){
            $total_month_list[] = array('date'=>$key, 'total'=>$value);
        }



        // Comparison function 
        function date_compare($element1, $element2) { 
            $datetime1 = strtotime($element1['date']); 
            $datetime2 = strtotime($element2['date']); 
            return $datetime1 - $datetime2; 
        }  
        
        // Sort the array  
        if(isset($total_day_list)){
            usort($total_day_list, 'date_compare'); 
        }

        if(isset($total_month_list)){
            usort($total_month_list, 'date_compare'); 
        }


        foreach($total_day_list as $key=>$value){
            $chart_list[] = ['y'=>$value['total'], 'label'=>$value['date']];
        }


        $isk_per_lp = '2000';
        break;
    default:
        break;

}

?>


<!DOCTYPE HTML>
<html>
<head>
<?php if ($_SESSION['access_token']){ ?>

<script>
window.onload = function () {
 
var chart = new CanvasJS.Chart("chartContainer", {
	title: {
		text: "LP FW Participation"
	},
	axisY: {
		title: "LP total"
	},
	data: [{
		type: "line",
		dataPoints: <?php echo json_encode($chart_list, JSON_NUMERIC_CHECK); ?>
	}]
});
chart.render();
 
}
<?php } ?>

</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
</head>
<body>
<h1 class="text-danger">Cherry LP Tool</h1>
<p> Please screenshot this table and send to corp </p>
<?php if ($_SESSION['access_token']){ ?>

<div id="table">
<table class="table">
<h2>Daily Totals</h2>
<thead>
    <tr>
      <th scope="col">Date</th>
      <th scope="col">Total</th>
    </tr>
  </thead>
  <tbody>
        <?php foreach ($total_day_list as $row) : ?>
        <tr>
            <td><?php echo $row['date']; ?></td>
            <td><?php echo number_format($row['total']). ' x '.$isk_per_lp.' ISK = '.number_format($row['total']*$isk_per_lp).' ISK'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div id="table2">
<h2>Monthly Totals</h2>
<table class="table">
<thead>
    <tr>
      <th scope="col">Date</th>
      <th scope="col">Total</th>
    </tr>
  </thead>
        <?php foreach ($total_month_list as $row) : ?>
        <tr>
            <td><?php echo $row['date']; ?></td>
            <td><?php echo number_format($row['total']). ' x '.$isk_per_lp.' ISK = '.number_format($row['total']*$isk_per_lp). ' ISK'; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<div id="chartContainer" style="height: 370px; width: 100%;"></div>
<?php } ?>

<script src="https://cdn.canvasjs.com/canvasjs.min.js"></script>
<?php echo $login_url ?>
</body>
</html>  