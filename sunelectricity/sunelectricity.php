!/usr/bin/php
<?php  
// This php program reads data from a growatt inverter
// 
// Thanks to Lennart Kuhlmeier for providing PVOUT_GROWATT.PY on http://www.sisand.dk/?page_id=139 
//

$serialdevice = '/dev/ttyUSB0';

$data = json_decode ('
{
			"sunelectricity": {
				"now":
				{
					"pv":
					{
					  "watt": null,
					  "volt": null,
					  "amp": null
					},
					"grid":
					{
					  "watt": null,
					  "frequency": null,
					  "volt": null,
					  "amp": null
					}
				},
				"today":
				{
					"kwh": null
				},
				"total":
				{
					"kwh": null
				}
			}
}
',true);

exec ('stty -F '.$serialdevice.'  1:0:8bd:0:3:1c:7f:15:4:5:1:0:11:13:1a:0:12:f:17:16:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0');

include("PhpSerial.php");
$serial = new PhpSerial;

echo "Opening Serial Port '".$serialdevice."'...\n";

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$serial->deviceSet($serialdevice);

// We can change the baud rate, parity, length, stop bits, flow control
$serial->confBaudRate(9600);
$serial->confParity("none");
$serial->confCharacterLength(8);
$serial->confStopBits(1);
$serial->confFlowControl("none");

if (!$serial->deviceOpen())
{
   echo ("Serial Port could not be opened...\n");
   exit (1);
}

echo "Opened Serial Port.\n";

// Open modbus
while (!$modbus->deviceOpened())
{
    $modbus->deviceInit($serialdevice,9600,'none',8,1,'none');
    $modbus->deviceOpen();
    if (!$modbus->deviceOpened()) sleep(5);
}


// Initialize tcpsocket
while (!$tcpsocket = stream_socket_server("tcp://0.0.0.0:58883", $errno, $errstr)) 
{
    echo "$errstr ($errno)\n";
    sleep(5);
}

$tcpsockets = array();
$tcpsocketClients = array();
array_push($tcpsockets, $tcpsocket);

date_default_timezone_set ("Europe/Amsterdam");


// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$timeout = 1;
$sendtimer = 0;
while(1)
{
        $readmask = $tcpsockets;
        $writemask = NULL;
        $errormask = NULL;
        $nroffd = stream_select($readmask, $writemask, $errormask, $timeout);
        $timeout = 1;
        foreach ($readmask as $i) 
        {
            if ($i === $tcpsocket) 
            {
                $conn = stream_socket_accept($tcpsocket);
                echo ("\nNew tcpsocket client connected!\n\n");
                array_push($tcpsockets, $conn);
                array_push($tcpsocketClients, $conn);
                echo ("Sending data to tcp client\n");
                fwrite($conn, json_encode($data). "\n\n");
            }
            else
            {
                $sock_data = fread($i, 1024);
                if (strlen($sock_data) === 0) { // connection closed
                    $key_to_del = array_search($i, $tcpsocketClients, TRUE);
                    unset($tcpsocketClients[$key_to_del]);
                    $key_to_del = array_search($i, $tcpsockets, TRUE);
                    unset($tcpsockets[$key_to_del]);
                } else if ($sock_data === FALSE) {
                    echo "Something bad happened";
                    fclose($i);
                    $key_to_del = array_search($i, $tcpsocketClients, TRUE);
                    unset($tcpsocketClients[$key_to_del]);
                    $key_to_del = array_search($i, $tcpsockets, TRUE);
                    unset($tcpsockets[$key_to_del]);
                } else {
                      echo ("Received from tcpsocket client: [" . $sock_data . "]\n");
                      if (trim($sock_data) == "getsunelectricitydata") 
                      {
                        echo ("Sending smartmeterdata to tcpsocketclient...\n");
                        fwrite($conn, json_encode($data)."\n\n");
                      }
              }
            }
          }

          if ($nroffd == 0)
          {
              if ($sendtimer == 0)
              {
                $TxBuffer = sprintf ("%c%c%c%c%c%c", 0x3F, 0x23, 1, 0x32, 0x41, 0);
                $wStringSum = 0;
                for($i=0;$i<strlen($TxBuffer);$i++)
                {
                  $wStringSum += (ord($TxBuffer[$i]) ^ $i);
                  if($wStringSum==0||$wStringSum>0xFFFF)$wStringSum = 0xFFFF;
                }
                $TxBuffer .= sprintf ("%c%c", $wStringSum >> 8, $wStringSum & 0xFF);
                echo "Sending: '".bin2hex($TxBuffer)."'\n" ;

                $serial->sendMessage($TxBuffer, 2);
                //$serial->sendMessage("Hello World!", 2);
                $sendtimer = 5;
              }
              
              if ($sendtimer == 4)
              {
                $TxBuffer = sprintf ("%c%c%c%c%c%c", 0x3F, 0x23, 1, 0x32, 0x42, 0);
                $wStringSum = 0;
                for($i=0;$i<strlen($TxBuffer);$i++)
                {
                  $wStringSum += (ord($TxBuffer[$i]) ^ $i);
                  if($wStringSum==0||$wStringSum>0xFFFF)$wStringSum = 0xFFFF;
                }
                $TxBuffer .= sprintf ("%c%c", $wStringSum >> 8, $wStringSum & 0xFF);
    
                echo "Sending: '".bin2hex($TxBuffer)."'\n" ;

                $serial->sendMessage($TxBuffer,1);
              }
              $sendtimer--;
              
              $message = $serial->readPort();
              
              if (strlen($message) > 0) echo ("Received: '".bin2hex($message)."'\n");

              if (strlen($message) > 20 && (ord($message[0]) == 0x23) && (ord($message[1]) == 0x3f) && (ord($message[2]) == 0x01) && (ord($message[3]) == 0x32) && (ord($message[4]) == 0x41))
              { 
    
                echo ("PV Volt1 = ".((ord($message[7]) << 8)| ord($message[8]))/10 . "\n");
                echo ("PV Volt2 = ".((ord($message[9]) << 8)| ord($message[10]))/10 . "\n");
                echo ("PV Watt  = ".((ord($message[11]) << 8)| ord($message[12]))/10 . "\n");
                echo ("AC Volt  = ".((ord($message[13]) << 8)| ord($message[14]))/10 . "\n");
                echo ("AC Amp   = " .((ord($message[15]) << 8)| ord($message[16]))/ 10 . "\n");
                echo ("AC Freq  = ".((ord($message[17]) << 8)| ord($message[18]))/100 . "\n");
                echo ("AC Watt  = ".((ord($message[19]) << 8)| ord($message[20]))/10 . "\n");
    
                $data["sunelectricity"]["now"]["pv"]["watt"]=((ord($message[11]) << 8)| ord($message[12]))/10;
                $data["sunelectricity"]["now"]["pv"]["volt"]=((ord($message[7]) << 8)| ord($message[8]))/10;
                $data["sunelectricity"]["now"]["grid"]["watt"]=((ord($message[19]) << 8)| ord($message[20]))/10;
                $data["sunelectricity"]["now"]["grid"]["frequency"]=((ord($message[17]) << 8)| ord($message[18]))/100;
                $data["sunelectricity"]["now"]["grid"]["volt"]=((ord($message[13]) << 8)| ord($message[14]))/10;
                $data["sunelectricity"]["now"]["grid"]["amp"]=((ord($message[15]) << 8)| ord($message[16]))/10;
                $data["sunelectricity"]["today"]["kwh"]=NULL;
                $data["sunelectricity"]["total"]["kwh"]=NULL;
              }


              if (strlen($message) > 20 && (ord($message[0]) == 0x23) && (ord($message[1]) == 0x3f) && (ord($message[2]) == 0x01) && (ord($message[3]) == 0x32) && (ord($message[4]) == 0x42) )
              { 
    
                echo ("Energy Today = ".(ord($message[13]) << 8 | ord($message[14]))/10 . "\n");
                echo ("Energy Total = ".(ord($message[15]) << 16 | ord($message[16]) << 16 | ord($message[17] <<8 | ord($message[18])) / 10 . "\n");

                $data["sunelectricity"]["today"]["kwh"]=(ord($message[13]) << 8 | ord($message[14]))/10;
                $data["sunelectricity"]["total"]["kwh"]=(ord($message[15]) << 16 | ord($message[16]) << 16 | ord($message[17] < 8 | ord($message[18])) / 10;

                echo (json_encode($data)."\n\n");
    
                sendToAllTcpSocketClients($tcpsocketClients, json_encode($data)."\n\n");
              }
            }
}

$serial->deviceClose();
exit(1);


function sendToAllTcpSocketClients($sockets, $msg)
{
   echo ("Sending smartmeterdata to all websocketclient...\n");
   foreach ($sockets as $conn) 
   {
     fwrite($conn, $msg);
   }
}


?>  
