<?php

namespace App\Http\Controllers;

use App\Models\FeedbackType;
use Illuminate\Http\Request;
use App\Models\Feedback;
use Illuminate\Support\Facades\Auth;

class FeedbackController extends Controller
{
    public function index()
    {
        $type = FeedbackType::get();
        //dd($type);
        return view('feedback',compact('type'));
    }
    public function submit(Request $request)
    {
        $feedbackData = $request->validate([
            'type' => 'required',
            'description' => 'required',
        ]);
        $feedbackData['user_id'] = Auth::id();

        Feedback::create($feedbackData);
        //dd("save");
        return redirect()->back()->with('status', 'Feedback Sent successfully');
    }
}
