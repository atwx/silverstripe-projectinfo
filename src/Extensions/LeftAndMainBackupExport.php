<?php

namespace Atwx\ProjectInfo\Extensions;

use SilverStripe\Core\Environment;
use SilverStripe\Admin\LeftAndMainExtension;
use SilverStripe\ORM\DB;

class LeftAndMainBackupExport extends LeftAndMainExtension
{
    private static $allowed_actions = array(
        'doBackup'
    );

    public function doBackup(){
        $host = Environment::getEnv('SS_DATABASE_SERVER');
        $user = Environment::getEnv('SS_DATABASE_USERNAME');
        $pass = Environment::getEnv('SS_DATABASE_PASSWORD');
        $name = Environment::getEnv('SS_DATABASE_NAME');
        \Spatie\DbDumper\Databases\MySql::create()
        ->setHost($host)
        ->setDbName($name)
        ->setUserName($user)
        ->setPassword($pass)
        ->dumpToFile('dump.sql');
//        $this->Export_Database($host,$user,$pass,$name,"dump.sql");
//        $mysqldump=exec('which mysqldump');
//        $command = "$mysqldump --opt -h $dbhost -u $dbuser -p $dbpass $dbname > $dbname.sql";
//        exec($command);
    }

    public function Export_Database($host,$user,$pass,$name,$backup_name=false )
    {
//        $mysqli = new \mysqli($host,$user,$pass,$name);
//        $mysqli->select_db($name);
//        $mysqli->query("SET NAMES 'utf8'");

        $queryTables    = DB::query('SHOW TABLES');
        foreach($queryTables as $row)
        {
            $target_tables[] = array_values($row)[0];
        }
        foreach($target_tables as $table)
        {
            if ($table != "group"){
                $result         =   DB::query('SELECT * FROM '.$table.';');
//                print_r($result);die();
//                $fields_amount  =   $result->field_count;
//                print_r($result);die();
//                $rows_num=$mysqli->affected_rows;
                $res            =   DB::query('SHOW CREATE TABLE '.$table);
                print_r($res->record());die();
                $TableMLine     =   $res->fetch_row();
                $content        = (!isset($content) ?  '' : $content) . "\n\n".$TableMLine[1].";\n\n";

                for ($i = 0, $st_counter = 0; $i < $fields_amount;   $i++, $st_counter=0)
                {
                    while($row = $result->fetch_row())
                    { //when started (and every after 100 command cycle):
                        if ($st_counter%100 == 0 || $st_counter == 0 )
                        {
                                $content .= "\nINSERT INTO ".$table." VALUES";
                        }
                        $content .= "\n(";
                        for($j=0; $j<$fields_amount; $j++)
                        {
                            if($row[$j] == null){
                                $row[$j] = 'NULL';
                            }
                            $row[$j] = str_replace("\n","\\n", addslashes($row[$j]));
                            if (isset($row[$j]))
                            {
                                $content .= '"'.$row[$j].'"' ;
                            }
                            else
                            {
                                $content .= '""';
                            }
                            if ($j<($fields_amount-1))
                            {
                                    $content.= ',';
                            }
                        }
                        $content .=")";
                        //every after 100 command cycle [or at last line] ....p.s. but should be inserted 1 cycle eariler
                        if ( (($st_counter+1)%100==0 && $st_counter!=0) || $st_counter+1==$rows_num)
                        {
                            $content .= ";";
                        }
                        else
                        {
                            $content .= ",";
                        }
                        $st_counter=$st_counter+1;
                    }
                } $content .="\n\n\n";
            }
        }
        //$backup_name = $backup_name ? $backup_name : $name."___(".date('H-i-s')."_".date('d-m-Y').")__rand".rand(1,11111111).".sql";
        $backup_name = $backup_name ? $backup_name : $name.".sql";
        header('Content-Type: application/octet-stream');
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"".$backup_name."\"");
        echo $content; exit;
    }
}
