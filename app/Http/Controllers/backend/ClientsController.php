<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Currency;
use App\Fund;
use App\FundOrder;
use App\User;
use App\Balance;
use App\Period;

class ClientsController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
     private function percent($user){
             if($user->hasRole('30')){
                     $userInitials = $user->funds()->where('type', 'initial')->get();
                     $userInvest = 0;
                     foreach($userInitials as $initial){
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

         $ch = curl_init( $url );

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
         $accepted_response = array( 200, 301, 302 );
         if( in_array( $httpcode, $accepted_response ) ) {
             return true;
         } else {
             return false;
         }

     }

     public function total(Request $request){
        $user = User::find($request->id);
        $balances = array();

        if($user->hasRole('30')){
          $periods = $user->periods()->get();
          foreach($periods as $period){
              $balancesP = Balance::Where('balances.type', 'fund')->where('user_id', null)->where('period_id', $period->id)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name')->get();
              foreach($balancesP as $balance){
                  $balances[$count] = new \stdClass();
                  if(property_exists($balances[$count], 'amount')){
                      $balances[$count]->amount = $balance->amount;
                      $balances[$count]->value = $balance->value;
                      $balances[$count]->symbol = $balance->symbol;
                      $balances[$count]->type = $balance->type;
                      $balances[$count]->name = $balance->name;
                  }
              }
          }

        }else{
          $balances = Balance::Where('balances.type', 'fund')->where('user_id', null)->where('period_id', null)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name')->get();
        }
        $usd = 0;
        $btc = 0;
        foreach($balances as $balance){
            if($balance->value == "coinmarketcap"){
              $url = 'api.coinmarketcap.com/v1/ticker/'. $balance->name;
                if($this->url_exists($url)){
                    $json = file_get_contents('https://api.coinmarketcap.com/v1/ticker/'. $balance->name);
                    $data = json_decode($json);
                    $balance->value = $data[0]->price_usd;
                    $balance->value_btc = $data[0]->price_btc;
                }else{
                    $balance->value = 0.1;
                    $balance->value_btc = 0.00001;
                }
            }

            if($balance->symbol == "VEF"){
              $balance->value_btc = 238000;
              $btcvalue = 0;
            }else{
              $btcvalue = $balance->amount * $balance->value_btc;
            }
            if($balance->symbol == "USD"){
              $json = file_get_contents('https://api.coinmarketcap.com/v1/ticker/bitcoin');
              $data = json_decode($json);
              $balance->value_btc = $data[0]->price_usd;
              $btcvalue = $balance->amount / $balance->value_btc;
            }
            $usdvalue = $balance->amount * $balance->value;
            $usd += $usdvalue;
            $btc += $btcvalue;
        }
        if($user->hasRole('30')){
            $percent = $this->percent($user);
            $usd = $usd * $percent;
            $btc = $btc * $percent;
        }
         return response()->json(['usd' => $usd, 'btc' => $btc], 202);
     }
     public function index(Request $request){
         $user = Auth::User();
         $searchValue = $request->searchvalue;
         $page = $request->page;
         $resultPage = $request->resultPage;
         $orderBy = $request->orderBy;
         $orderDirection = $request->orderDirection;
         $total = 0;

         //Select Users
         $query = User::whereHas('roles', function ($query2) {
             $query2->where('code', '30');
        })->WhereNotIn('users.id', [$user->id]);
         //Search by

         if($searchValue != '')
         {
                 $query->Where(function($query) use($searchValue){
                     $query->Where('name', 'like', '%'.$searchValue.'%')
                     ->orWhere('username', 'like', '%'.$searchValue.'%')
                     ->orWhere('email', 'like', '%'.$searchValue.'%')
                     ->orWhere('created_at', 'like', '%'.$searchValue.'%')
                     ->orWhere('updated_at', 'like', '%'.$searchValue.'%');
                 });

         }
         //Order By

         if($orderBy != '')
         {
             if($orderDirection != '')
             {
                 $query->orderBy($orderBy, 'desc');
             }else{
                 $query->orderBy($orderBy);
             }
         }else if($orderDirection != ''){
             $query->orderBy('created_at');
         }else{
              $query->orderBy('created_at', 'desc');
         }

         if($resultPage == null || $resultPage == 0)
         {
             $resultPage = 10;
         }

         //Get Total of fees
         $total  =  $query->get()->count();
         if($page > 1)
         {
              $query->offset(    ($page -  1)   *    $resultPage);
         }


         $query->limit($resultPage);

         $users  =  $query->get();

         foreach($users as $user){
             $funds = $user->funds()->where('type', 'initial')->get();
             if(isset($funds)){
                 $user->amount = 0;
                 foreach($funds as $fund){
                     $user->amount += $funds->amount;
                 }
             }else{
                 $user->amount = 0;
             }
         }
         //Get fees by month and year

         return response()->json(['page' => $page, 'result' => $users,'total' => $total], 202);
     }

     public function indexCurrency(Request $request){

         $user = User::find($request->id);
         $searchValue = $request->searchvalue;
         $page = $request->page;
         $resultPage = $request->resultPage;
         $orderBy = $request->orderBy;
         $orderDirection = $request->orderDirection;
         $total = 0;

         $balancesCurrency = array();

         if($user->hasRole('30')){
           $periods = $user->periods()->get();
           foreach($periods as $period){
               $count = 0;
               $query = Balance::Where('balances.type', 'fund')->where('user_id', null)->where('period_id', $period->id)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name');

               if($searchValue != '')
               {
                       $query->Where(function($query) use($searchValue){
                           $query->Where('symbol', 'like', '%'.$searchValue.'%')
                           ->orWhere('amount', 'like', '%'.$searchValue.'%')
                           ->orWhere('value', 'like', '%'.$searchValue.'%');
                       });
               }

               //Order By

               if($orderBy != '')
               {
                   if($orderDirection != '')
                   {
                       $query->orderBy($orderBy, 'desc');
                   }else{
                       $query->orderBy($orderBy);
                   }
               }else if($orderDirection != ''){
                   $query->orderBy('balances.amount');
               }else{
                    $query->orderBy('balances.amount', 'desc');
               }

               if($resultPage == null || $resultPage == 0)
               {
                   $resultPage = 10;
               }

               //Get Total of fees
               $total  =  $query->get()->count();

               if($page > 1)
               {
                    $query->offset(    ($page -  1)   *    $resultPage);
               }


               $query->limit($resultPage);
               $balancesCurrencyP  =  $query->get();

               foreach($balancesCurrencyP as $balance){
                   $balancesCurrency[$count] = new \stdClass();
                   if(property_exists($balancesCurrency[$count], 'amount')){
                       $balancesCurrency[$count]->amount = $balance->amount;
                       $balancesCurrency[$count]->value = $balance->value;
                       $balancesCurrency[$count]->symbol = $balance->symbol;
                       $balancesCurrency[$count]->type = $balance->type;
                       $balancesCurrency[$count]->name = $balance->name;
                   }
               }

           }

           //Search by
         }else{
           $query = Balance::Where('balances.type', 'fund')->where('user_id', null)->where('period_id', null)->leftJoin('currencies', 'currencies.id', '=', 'balances.currency_id')->select('balances.*', 'symbol', 'value', 'currencies.type', 'name');
           //Search by
           if($searchValue != '')
           {
                   $query->Where(function($query) use($searchValue){
                       $query->Where('symbol', 'like', '%'.$searchValue.'%')
                       ->orWhere('amount', 'like', '%'.$searchValue.'%')
                       ->orWhere('value', 'like', '%'.$searchValue.'%');
                   });
           }

           //Order By

           if($orderBy != '')
           {
               if($orderDirection != '')
               {
                   $query->orderBy($orderBy, 'desc');
               }else{
                   $query->orderBy($orderBy);
               }
           }else if($orderDirection != ''){
               $query->orderBy('balances.amount');
           }else{
                $query->orderBy('balances.amount', 'desc');
           }

           if($resultPage == null || $resultPage == 0)
           {
               $resultPage = 10;
           }

           //Get Total of fees
           $total  =  $query->get()->count();

           if($page > 1)
           {
                $query->offset(    ($page -  1)   *    $resultPage);
           }


           $query->limit($resultPage);
           $balancesCurrency  =  $query->get();

         }

         foreach($balancesCurrency as $balance){
             if($balance->symbol == "USD"){
                $balance->value = 1;
             }
             if($balance->symbol == "VEF"){
                $balance->value = 217200;
             }

         }
         foreach($balancesCurrency as $balance){
             if($balance->value == "coinmarketcap"){
               $url = 'api.coinmarketcap.com/v1/ticker/'. $balance->name;
                 if($this->url_exists($url)){
                     $json = file_get_contents('https://api.coinmarketcap.com/v1/ticker/'. $balance->name);
                     $data = json_decode($json);
                     $balance->value = $data[0]->price_usd;
                 }else{
                     $balance->value = 0.1;
                 }
             }
         }

         if($user->hasRole('30')){
             $percent = $this->percent($user);
             foreach($balancesCurrency as $balance){
                 $newbalance = $balance->amount * $percent;
                 $balance->amount = $newbalance;
             }
         }

         return response()->json(['page' => $page, 'result' => $balancesCurrency, 'total' => $total,], 202);
     }

     public function initial(Request $request){
         $request->validate([
              'date' => 'required',
             'amount' => 'required| min:01',
             'id' => 'required',
         ]);

         $currency = Currency::Where('symbol', 'USD')->first();
         $id = $request->id;
         $amount = $request->amount;
         $date = $request->date;
         $user = User::find($id);

         $period = Period::where('close_date', null)->first();

         $hasini = $user->funds()->where('type', 'initial')->where('period_id', $period->id)->first();


         if(isset($hasini)){
             $fund = Fund::find($hasini->id);

             $poolP = Fund::where('user_id', null )->where('type', 'initial')->where('period_id', $period->id)->first();
             $newamountP = $poolP->amount - $fund->amount;
             $newamountP = $newamountP + $amount;

             $poolfP = Fund::find($poolP->id);
             $poolfP->amount = $newamountP;
             $poolfP->save();

             $pool = Fund::where('user_id', null )->where('type', 'initial')->where('period_id', null)->first();
             $newamount = $pool->amount - $fund->amount;
             $newamount = $newamount + $amount;

             $poolf = Fund::find($pool->id);
             $poolf->amount = $newamount;
             $poolf->save();

             $newPamount = $period->open_amount - $fund->amount;
             $newPamount = $newPamount + $amount;

             $periodf = Period::find($period->id);
             $periodf->open_amount = $newamount;
             $periodf->save();



             $balance = Balance::where('user_id', null)->where('period_id', null)->where('currency_id', '2')->first();
             $newbalance = $balance->amount - $fund->amount;
             $newbalance = $newbalance + $amount;

             $balanceP = Balance::where('user_id', null)->where('period_id', $period->id)->where('currency_id', '2')->first();
             $newbalanceP = $balanceP->amount - $fund->amount;
             $newbalanceP = $newbalanceP + $amount;

             $balancefP = Balance::find($balanceP->id);
             $balancefP->amount = $newbalanceP;
             $balancefP->save();

             $balancef = Balance::find($balance->id);
             $balancef->amount = $newbalance;
             $balancef->save();
         }else{
             $fund = new Fund;

             $pool = Fund::where('user_id', null)->where('type', 'initial')->where('period_id', null)->first();
             $newamount = $pool->amount + $amount;

             $poolf = Fund::find($pool->id);
             $poolf->amount = $newamount;
             $poolf->save();

             $poolP = Fund::where('user_id', null)->where('type', 'initial')->where('period_id', $period->id)->first();
             $newamountP = $poolP->amount + $amount;

             $poolfP = Fund::find($poolP->id);
             $poolfP->amount = $newamountP;
             $poolfP->save();

             $newPamount = $period->open_amount + $amount;

             $periodf = Period::find($period->id);
             $periodf->open_amount = $newamount;
             $periodf->save();

             $balance = Balance::where('user_id', null)->where('period_id', null)->where('currency_id', '2')->first();
             $newbalance = $balance->amount + $amount;

             $balanceP = Balance::where('user_id', null)->where('period_id', $period->id)->where('currency_id', '2')->first();
             $newbalanceP = $balanceP->amount + $amount;


             $balancefP = Balance::find($balanceP->id);
             $balancefP->amount = $newbalanceP;
             $balancefP->save();

             $balancef = Balance::find($balance->id);
             $balance->amount = $newbalance;
             $balance->save();
         }

         $fund->amount = $amount;
         $fund->reference = 'initial';
         $fund->active = 1;
         $fund->comment = 'Initial Invest';
         $fund->type = "initial";
         $fund->created_at = $date;
         $fund->user()->associate($id);
         $fund->period()->associate($period->id);
         $fund->currency()->associate($currency);

         $fund->save();

          $user->periods()->attach($period->id);
          $user->save();

         return response()->json(['result' => 'Success'], 202);
     }

     private function generate($longitud) {
         $key = '';
         $pattern = '1234567890';
         $max = strlen($pattern)-1;
         for($i=0;$i < $longitud;$i++) $key .= $pattern{mt_rand(0,$max)};
         return $key;
     }



}
