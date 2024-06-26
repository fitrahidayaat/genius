<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\UserLoginRequest;
use App\Http\Requests\UserRegisterRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserController extends Controller
{
    public function faq()
    {
        $qna_pairs = [
            [
                "question" => "What is the purpose of this app?",
                "answer" => "This app is designed to help students learn and practice math in a fun and interactive way."
            ],
            [
                "question" => "How do I get started?",
                "answer" => "You can get started by creating an account and logging in. If you are a student, you will need an invitation code from your teacher to create an account."
            ],
            [
                "question" => "How do I get an invitation code?",
                "answer" => "Your teacher will provide you with an invitation code that you can use to create an account."
            ],
            [
                "question" => "How do I create an account?",
                "answer" => "You can create an account by clicking on the 'Register' button on the home page and filling out the registration form."
            ],
            [
                "question" => "How do I log in?",
                "answer" => "You can log in by clicking on the 'Login' button on the home page and entering your email and password."
            ],
            [
                "question" => "How do I log out?",
                "answer" => "You can log out by clicking on the 'Logout' button on the dashboard page."
            ]
        ];

        return view('faq', [
            "qna_pairs" => $qna_pairs,
        ]);
    }

    public function registerView(Request $request)
    {
        if (session('token')) {
            return redirect('/dashboard');
        }
        return view('register');
    }

    public function selectRoleView()
    {
        return view('select-role');
    }

    public function selectRole(Request $request)
    {
        $user = auth()->user();
        $role = $request->input('role');
        
        if($role == "student"){
            return redirect('/invitation-code');
        }

        User::where('id', $user->id)->update([
            'role' => $role
        ]);

        $teacher = new Teacher();
        $teacher->user_id = $user->id;
        $teacher->code = Str::random(6);
        $teacher->save();

        return redirect('/dashboard');
    }

    public function invitationCodeView()
    {
        return view('invitation-code');
    }

    public function invitationCode(Request $request)
    {
        $user = auth()->user();
        $code = $request->input('code');
        
        $teacher = Teacher::where('code', $code)->first();
        if(!$teacher){
            return redirect('/invitation-code')->withErrors([
                "message" => "Invalid invitation code"
            ])->withInput();
        }

        User::where('id', $user->id)->update([
            'role' => 'student'
        ]);

        $student = new Student();
        $student->user_id = $user->id;
        $student->teacher_id = $teacher->id;
        $student->points = 0;
        $student->save();

        return redirect('/dashboard');
    }

    public function register(UserRegisterRequest $request)
    {
        $data = $request->validated();

        if (User::where('email', $data['email'])->exists()) {
            if ($request->is('api/*')) {
                throw new HttpResponseException(response([
                    "errors" => [
                        "message" => [
                            "Email already exists"
                        ]
                    ]
                ], 400));
            } else {
                return redirect('/register')->withErrors([
                    "message" => "Email already exists"
                ])->withInput();
            }
        }

        $user = new User();
        $user->name = $data['name'];              
        $user->email = $data['email'];
        $user->password = Hash::make($data['password']);
        $user->image_path = "default.jpg";
        $user->remember_token = Str::uuid()->toString();
        $user->save();

        Auth::login($user);
        session(['token' => $user->remember_token]);

        return redirect('/select-role');
    }


    public function loginView(Request $request)
    {
        if (session('token')) {
            return redirect('/dashboard');
        }
        return view('login');
    }

    public function login(UserLoginRequest $request)
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            if ($request->is('api/*')) {
                throw new HttpResponseException(response([
                    "errors" => [
                        "message" => [
                            "Invalid username or password"
                        ]
                    ]
                ], 401));
            } else {
                return redirect('/login')->withErrors([
                    "message" => "Invalid username or password"
                ])->withInput();
            }
        }

        $user->remember_token = Str::uuid()->toString();
        $user->save();

        Auth::login($user);
        session(['token' => $user->remember_token]);

        // check if role is null
        if ($user->role == null) {
            return redirect('/select-role');
        } else{
            return redirect('/dashboard');
        }
    }

    public function logout(Request $request)
    {
        $token = session('token');
        $user = User::where('remember_token', $token)->first();
        $user->remember_token = null;
        $user->save();
        session()->forget('token');
        return redirect('/');
    }

    public function registerParentView(Request $request)
    {
        return view('parent.register');
    }

    public function registerChildView(Request $request)
    {
        return view('child.register');
    }

    public function editProfile(Request $request)
    {
        $name = $request->input('name');
        $email = $request->input('email');
        $oldPassword = $request->input('old-password');
        $newPassword = $request->input('new-password');


        // validate 
        $request->validate([
            'name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'new-password' => 'required|min:8|max:255',
            'old-password' => 'required'
        ]);

        // check if exist
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // check if password is correct
        if (!Hash::check($oldPassword, $user->password)) {
            return redirect('/setting')->withErrors([
                "message" => "Invalid password"
            ])->withInput(); 
        }

        // update user
        $user->name = $name;
        $user->password = Hash::make($newPassword);
        // check if image is uploaded
        if ($request->hasFile('image')) {
            Log::info('has image');
            // get the image file
            $image = $request->file('image');
            // get the original file name
            $filename = $image->getClientOriginalName();
            // store the image in the 'public' disk, under the 'images' directory
            $path = $image->storeAs('images/'.$user->id, 'profile_picture', 'public');
            // save the image path in the database
            $user->image_path = $path;
        }
        $user->save();

        // // return redirect with success message
        return redirect('/setting')->with('message', 'Profile updated successfully');
    }
}
