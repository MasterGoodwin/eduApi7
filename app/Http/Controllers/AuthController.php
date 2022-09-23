<?php

namespace App\Http\Controllers;

use App\MCMAutoUser;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserModeration;
use function response;

class AuthController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json('OK');
    }

    public function register(Request $request): JsonResponse
    {
        $user = User::where('email', $request->get('email'))->first();
        if ($user) {
            return response()->json([
                'result' => false,
                'message' => 'Пользователь с адресом электронной почты ' . $request->get('email') . ' уже существует.'
            ], 401);
        } else {
            $user = User::create([
                'email' => $request->get('email'),
                'password' => md5($request->get('password')),
            ]);
            $this->sendUserModerationMail($user);
            return response()->json([
                'result' => true,
                'message' => 'Пользователь успешно зарегистрирован'
            ], 201);
        }
//        DB::transaction(function () use ($request) {});
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);
        $tokenCan = [];
        $tokenCan[] = 'user';

        if ($request->type === 'mcmauto') {
            $user = User::where('email', $request->username)->first();
            if ($user) {
                if (md5($request->password) === $user->password) {
                    if ($user->status === 1) {
                        return response()->json(['token' => $user->createToken('edu', $tokenCan)->plainTextToken]);
                    } else {
                        return response()->json([
                            'result' => false,
                            'message' => 'Пользователь на модерации'
                        ], 401);
                    }
                } else {
                    return response()->json([
                        'result' => false,
                        'message' => 'Не верный пароль или пользователь не найден'
                    ], 401);
                }
            }

            $mcmautoUser = MCMAutoUser::where('email', $request->username)->first();
            if ($mcmautoUser && md5($request->password) === $mcmautoUser->password) {
                $user = User::create([
                    'email' => $request->username,
                    'password' => $mcmautoUser->password,
                ]);
                $this->sendUserModerationMail($user);
                return response()->json([
                    'result' => false,
                    'message' => 'Пользователь на модерации'
                ], 401);
//            return response()->json(['token' => $user->createToken('edu', $tokenCan)->plainTextToken]);
            }

        }

        if ($request->type === 'company') {
            try {
                $ch = curl_init('http://mx.wlbs.ru/upr_mcm/hs/API/edu_auth/');
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $request->username . ":" . $request->password);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['request' => true]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $res = json_decode(curl_exec($ch));
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200) {
                    $user = User::where('cid', $res->id)->first();
                    if (!$user) {
                        $user = User::create([
                            'cid' => $res->id,
                            'name' => $res->fullname,
                            'username1c' => $res->name,
                            'password' => md5($request->get('password')),
                        ]);
                        $this->sendUserModerationMail($user);
                        return response()->json([
                            'result' => false,
                            'message' => 'Пользователь успешно зарегистрирован'
                        ], 201);

                    } else {
                        if ($user->status === 1) {
                            return response()->json(['token' => $user->createToken('edu', $tokenCan)->plainTextToken]);
                        } else {
                            return response()->json([
                                'result' => false,
                                'message' => 'Пользователь на модерации'
                            ], 401);
                        }

                    }
                }

            } catch (\Exception $e) {
                Log::error($e->getMessage());
            }

        }

        // todo: авторизация 1с пользователей

        return response()->json([
            'result' => false,
            'message' => 'Не верный пароль или пользователь не найден'
        ], 401);
    }

    public function logout(Request $request){
        $request->user()->currentAccessToken()->delete();
    }

    private static function sendUserModerationMail($user) {
        Mail::to('anf@wlbs.ru')->send(new UserModeration($user));
    }
}
