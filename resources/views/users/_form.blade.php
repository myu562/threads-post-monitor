@error('guard') <div class="err-box mt-0 mb-3" role="alert">{{ $message }}</div> @enderror

<label for="name" class="mt-0">{{ __('users.form.name') }}</label>
<input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255">
@error('name') <div class="error">{{ $message }}</div> @enderror

<label for="email">{{ __('users.form.email') }}</label>
<input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="off">
@error('email') <div class="error">{{ $message }}</div> @enderror

<label for="password">{{ $user->exists ? __('users.form.password_reset') : __('users.form.password') }}</label>
<input id="password" type="password" name="password" minlength="12" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}>
<div class="hint">{{ __('users.form.hint', ['min' => 12]) }}</div>
@error('password') <div class="error">{{ $message }}</div> @enderror

<label for="password_confirmation">{{ __('users.form.confirm') }}</label>
<input id="password_confirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}>

<input type="hidden" name="is_admin" value="0">
<label class="check"><input type="checkbox" name="is_admin" value="1" {{ old('is_admin', $user->is_admin) ? 'checked' : '' }}> {{ __('users.form.admin') }}</label>
<input type="hidden" name="is_active" value="0">
<label class="check"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}> {{ __('users.form.active') }}</label>
