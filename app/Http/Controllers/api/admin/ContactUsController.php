<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;

class ContactUsController extends Controller
{
    public function index()
    {
        $contacts = Contact::orderByDesc('created_at')
            ->paginate(10)
            ->through(function ($contact) {
                return [
                    'id' => $contact->id,
                    'f_name' => $contact->f_name,
                    'l_name' => $contact->l_name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'message' => $contact->message,
                    'created_at' => $contact->created_at->format('Y-m-d H:i A'),
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $contacts,
        ]);
    }
}
