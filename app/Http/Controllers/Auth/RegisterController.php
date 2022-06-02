<?php

namespace App\Http\Controllers\Auth;

use App\Classes\Pterodactyl;
use App\Http\Controllers\Controller;
use App\Models\Settings;
use App\Models\User;
use App\Notifications\ReferralNotification;
use App\Providers\RouteServiceProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        $validationRules = [
            'name'                 => ['required', 'string', 'max:30', 'min:4', 'alpha_num', 'unique:users'],
            'email'                => ['required', 'string', 'email', 'max:64', 'unique:users'],
            'password'             => ['required', 'string', 'min:8', 'confirmed'],
        ];
        if (config('SETTINGS::RECAPTCHA:ENABLED') == 'true') {
            $validationRules['g-recaptcha-response'] = ['required', 'recaptcha'];
        }

        if (config('SETTINGS::SYSTEM:REGISTER_IP_CHECK', 'true') == 'true') {

            //check if ip has already made an account
            $data['ip'] = session()->get('ip') ?? request()->ip();
            if (User::where('ip', '=', request()->ip())->exists()) session()->put('ip', request()->ip());
            $validationRules['ip']  = ['unique:users'];
            return Validator::make($data, $validationRules, [
                'ip.unique' => "You have already made an account! Please contact support if you think this is incorrect."

            ]);
        }

        return Validator::make($data, $validationRules);
    }

    /**
     * Create a unique Referral Code for User
     * @return string
     */
    protected function createReferralCode(){
        $referralcode = STR::random(8);
        if (User::where('referral_code', '=', $referralcode)->exists()) {
            $this->createReferralCode();
        }
        return $referralcode;
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param array $data
     * @return User
     */
    protected function create(array $data)
    {
        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'credits'      => config('SETTINGS::USER:INITIAL_CREDITS', 150),
            'server_limit' => config('SETTINGS::USER:INITIAL_SERVER_LIMIT', 1),
            'password'     => Hash::make($data['password']),
            'referral_code' => $this->createReferralCode(),

        ]);

        $response = Pterodactyl::client()->post('/application/users', [
            "external_id" => App::environment('local') ? Str::random(16) : (string)$user->id,
            "username"    => $user->name,
            "email"       => $user->email,
            "first_name"  => $user->name,
            "last_name"   => $user->name,
            "password"    => $data['password'],
            "root_admin"  => false,
            "language"    => "en"
        ]);

        if ($response->failed()) {
            $user->delete();
            throw ValidationException::withMessages([
                'ptero_registration_error' => [__('Account already exists on Pterodactyl. Please contact the Support!')],
            ]);
        }

        $user->update([
            'pterodactyl_id' => $response->json()['attributes']['id']
        ]);

        //INCREMENT REFERRAL-USER CREDITS
        if(!empty($data['referral_code'])){
            $ref_code = $data['referral_code'];
            $new_user = $user->id;
            if($ref_user = User::query()->where('referral_code', '=', $ref_code)->first()) {
                $ref_user->increment('credits', config("SETTINGS::REFERRAL::REWARD"));
                $ref_user->notify(new ReferralNotification($ref_user->id,$new_user));

                //INSERT INTO USER_REFERRALS TABLE
                DB::table('user_referrals')->insert([
                    'referral_id' => $ref_user->id,
                    'registered_user_id' => $user->id,
                    'created_at' =>  Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }

        }

        return $user;
    }
}
