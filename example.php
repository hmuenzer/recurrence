<?php

        use \hmuenzer\Recurrence;
        require_once('./src/Recurrence.php');   //you don't need this, if you use an autoloader

        $options = array(
          'dtstart' => '19970902T090000',
          'dtend'   => '19970902T100000',
          'tzid'    => 'America/New_York',
          'rrule'   => 'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH'
          );

        $recurrence = new Recurrence($options);
        $recurrence->format = 'Ymd\THisO';

        while($date = $recurrence->next()){
          print_r($date);
          if($z++ > 100) break;
          }

?>