<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\History;
use App\User;
use App\Balance;
use App\Fund;
use Carbon\Carbon;

class dailyHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'history:daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update daily historical data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

     private function percent($user){
             if($user->hasRole('30')){
                    $userInitials = $user->funds()->where('type', 'initial')->get();
                    $userInvest = 0;
                    foreach ($userInitials as $initial) {
                      $userInvest += $initial->amount;
                    }
                    $fundInitial = Fund::Where('user_id', null)->where('type', 'initial')->where('period_id', null)->first();
                    $fundInvest = $fundInitial->amount;
                    $percent = $userInvest / $fundInvest;
                    return $percent;
             }
     }

     private function url_exists( $url = NULL ) {

         if( empty( $url ) ){
             return false;
         }

         $ch = curl_init($url);

         //Establecer un tiempo de espera
         curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
         curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );

         //establecer NOBODY en true para hacer una solicitud tipo HEAD
         curl_setopt( $ch, CURLOPT_NOBODY, true );
         //Permitir seguir redireccionamientos
         curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
         //recibir la respuesta como string, no output
         curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

         $data = curl_exec( $ch );

         //Obtener el código de respuesta
         $httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
         //cerrar conexión
         curl_close( $ch );
         //Aceptar solo respuesta 200 (Ok), 301 (redirección permanente) o 302 (redirección temporal)
         $accepted_response = array( 200, 301, 302);
         if( in_array( $httpcode, $accepted_response ) ) {
             return true;
         } else {
             return false;
         }

     }

    public function handle()
    {
        //
        $users = User::whereHas('roles', function ($query) {
          $query->where('code', '30');
        })->get();

        $today = Carbon::now();

        foreach($users as $user){
          if($user->histories()->first() !== null && $user->periods()->first() !== null){

              $initial = $user->histories()->where('type', 'daily')->get()->last();
              $periods = $user->periods()->get();
              $initialT = Carbon::parse($initial->register);

              $diffD = $initialT->diffInDays($today);

              $init = $initialT;
              for($i = 1;$i <= $diffD; $i++){
                $balances = array();
                $init = $init->addDays(1);
                $sum = 0;
                $initstamp = $init->timestamp;
                $percent = $this->percent($user);
                  $count = 0;
                  $balancesP = Balance::Where('balances.type', 'fund')->where('user_id', null)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name')->get();
                  foreach($balancesP as $balance){
                    if(empty($balances[$count])){
                      $balances[$count] = new \stdClass();
                      $balances[$count]->amount = $balance->amount  * $percent;
                      $balances[$count]->value = $balance->value;
                      $balances[$count]->symbol = $balance->symbol;
                      $balances[$count]->type = $balance->type;
                      $balances[$count]->name = $balance->name;
                      $balances[$count]->value_btc = 0;
                    }else{
                      foreach ($balances as $bal) {
                        if($bal->symbol == $balance->symbol){
                          $newBals = $bal->amount + ($balance->amount  * $percent);
                          $bal->amount = $newBals;
                        }
                      }
                    }
                      $count += 1;
                  }

                  foreach($balances as $balance){
                      if($balance->amount > 0){
                        if($balance->symbol == 'NPXS'){
                          $symbol = 'PXS';
                        }else{
                          $symbol = $balance->symbol;
                        }
                        $json = file_get_contents('https://min-api.cryptocompare.com/data/pricehistorical?fsym='.$symbol.'&tsyms=USD&ts='.$initstamp);
                        $data = json_decode($json);
                        if(isset($data->Response)){
                          if(strtolower($balance->symbol) == 'origin' || (strtolower($balance->symbol) == 'sdt' || strtolower($balance->symbol) == 'tari')){
                            $balance->value = 1;
                          }else{
                            if(strtolower($symbol) == 'npxs'){
                              $balance->value = 0.001;
                            }else{
                              $json = file_get_contents('https://min-api.cryptocompare.com/data/pricehistorical?fsym=ETH&tsyms=USD&ts='.$initstamp);
                              $data = json_decode($json);
                              $balance->value = $data->ETH->USD;
                            }
                          }
                        }else{
                          $balance->value = $data->$symbol->USD;
                        }
                          $na = $balance->amount * $balance->value;

                      }else{
                         $na = 0;
                      }
                      $sum += $na;
                  }
                  $history = new History;
                  $history->register = $init;
                  $history->amount = $sum;
                  $history->type = "daily";
                  $history->user()->associate($user);
                  $history->save();
              }
            }
          }

          $historical = History::Where('user_id', null)->where('type', 'daily')->get()->last();
          $attributes = isset($historical->amount) ? true : false;

          if($attributes){

              $initialGT = Carbon::parse($historical->register);

              $diffGD = $initialGT->diffInDays($today);

              $initG = $initialGT;
              for($i = 1;$i <= $diffGD; $i++){
                $initG = $initG->addDays(1);
                $sum = 0;
                $initGstamp = $initG->timestamp;
                $balances = Balance::Where('balances.type', 'fund')->where('user_id', null)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name')->get();
                  foreach($balances as $balance){
                      if($balance->amount > 0){
                        if($balance->symbol == 'NPXS'){
                          $symbol = 'PXS';
                        }else{
                          $symbol = $balance->symbol;
                        }
                        $json = file_get_contents('https://min-api.cryptocompare.com/data/pricehistorical?fsym='.$symbol.'&tsyms=USD&ts='.$initGstamp);
                        $data = json_decode($json);
                        if(isset($data->Response)){
                          if(strtolower($balance->symbol) == 'origin' || (strtolower($balance->symbol) == 'sdt' || strtolower($balance->symbol) == 'tari')){
                            $balance->value = 1;
                          }else{
                            if(strtolower($symbol) == 'npxs'){
                              $balance->value = 0.001;
                            }else{
                              $json = file_get_contents('https://min-api.cryptocompare.com/data/pricehistorical?fsym=ETH&tsyms=USD&ts='.$initstamp);
                              $data = json_decode($json);
                              $balance->value = $data->ETH->USD;
                            }
                          }
                        }else{
                          $balance->value = $data->$symbol->USD;
                        }

                          $newamount = $balance->amount * $balance->value;
                      }else{
                         $newamount = 0;
                      }
                      $sum += $newamount;
                  }
                  $history = new History;
                  $history->register = $initG;
                  $history->amount = $sum;
                  $history->type = "daily";
                  $history->save();
              }

          }
    }
}
