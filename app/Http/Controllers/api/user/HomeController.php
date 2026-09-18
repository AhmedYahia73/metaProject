<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactUsRequest;
use App\Mail\ContactUsMail;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends Controller
{
    /**
     * Fetch all packages with localized name according to language (en / ar).
     *
     * @queryParam lang string Language code (ar or en). Defaults to ar. Example: ar
     */
    public function packages(Request $request): JsonResponse
    {
        $request->validate([
            'lang' => 'required|in:ar,en',
        ]);
        $rawLang = $request->query('lang', $request->header('Accept-Language', 'ar'));
        $lang = str_starts_with(strtolower((string) $rawLang), 'en') ? 'en' : 'ar';

        $packages = Package::with(['discount', 'tax'])
            ->latest()
            ->get()
            ->map(function (Package $package) use ($lang) {
                $names = is_array($package->name)
                    ? $package->name
                    : (json_decode((string) $package->name, true) ?: []);

                $localizedName = $names[$lang] ?? $names['en'] ?? $names['ar'] ?? (is_string($package->name) ? $package->name : '');

                return [
                    'id' => $package->id,
                    'name' => $localizedName,
                    'names' => $names,
                    'msg_number' => $package->msg_number,
                    'price' => $package->price,
                    'months' => $package->months,
                    'discount_id' => $package->discount_id,
                    'tax_id' => $package->tax_id,
                    'discount' => $package->discount,
                    'tax' => $package->tax,
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'lang' => $lang,
            'data' => $packages,
        ]);
    }

    /**
     * Submit contact us form and send email to admin.
     * Rate limited to 2 submissions per 5 minutes.
     */
    public function contactUs(ContactUsRequest $request): JsonResponse
    {
        $recipient = config('mail.my_email', env('My_Email', 'ahmedahmadahmid73@gmail.com'));

        try {
            Mail::to($recipient)->send(new ContactUsMail($request->validated()));

            return response()->json([
                'status' => true,
                'message' => 'Your message has been sent successfully.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send Contact Us email: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send message. Please try again later.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
