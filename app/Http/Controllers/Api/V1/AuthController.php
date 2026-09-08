<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session authentication for the SPA (Sanctum's stateful flow) and the
 * membership list the organization switcher is built from.
 */
class AuthController extends Controller
{
    /**
     * Log in and start a session.
     */
    public function login(Request $request): JsonResponse
    {
        $this->requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        $request->session()->regenerate();

        return response()->json($this->profile($request->user()));
    }

    /**
     * End the session.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->requireSession($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ログアウトしました。']);
    }

    /**
     * The signed-in user and the organizations they belong to.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    /**
     * Sanctum only starts a session for requests it recognises as coming from
     * the SPA (by Origin or Referer). Without one there is nowhere to keep the
     * login, so say so plainly instead of failing deeper in the stack.
     */
    protected function requireSession(Request $request): void
    {
        abort_unless($request->hasSession(), 419, 'セッションを開始できませんでした。ページを再読み込みしてください。');
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(User $user): array
    {
        $organizations = $user->organizations()
            ->with('plan')
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'role' => $organization->pivot->role->value,
                'plan' => $organization->plan === null ? null : [
                    'code' => $organization->plan->code,
                    'name' => $organization->plan->name,
                    'product' => $organization->plan->product,
                ],
            ]);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'organizations' => $organizations,
        ];
    }
}
