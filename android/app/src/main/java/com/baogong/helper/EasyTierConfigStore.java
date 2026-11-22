package com.baogong.helper;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * V4 EasyTier 配置存储：TOML 配置（含 network_secret）绝不明文落盘。
 * 复用与 WireGuard 相同的 Android Keystore AES-256/GCM 方案，但使用独立的
 * 密钥 alias 与 SharedPreferences 文件，避免与 WG 配置互相覆盖。
 */
public final class EasyTierConfigStore {

    private static final String PREFS = "bg_easytier";
    private static final String KEY_IV = "iv";
    private static final String KEY_DATA = "data";
    private static final String KEY_ENABLED = "enabled";
    private static final String ALIAS = "bg_easytier_conf_key";
    private static final String ANDROID_KEYSTORE = "AndroidKeyStore";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";

    private EasyTierConfigStore() {
    }

    private static SharedPreferences prefs(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    private static SecretKey getOrCreateKey() throws Exception {
        KeyStore ks = KeyStore.getInstance(ANDROID_KEYSTORE);
        ks.load(null);
        if (ks.containsAlias(ALIAS)) {
            return (SecretKey) ks.getKey(ALIAS, null);
        }
        KeyGenerator generator = KeyGenerator.getInstance(
                KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE);
        KeyGenParameterSpec spec = new KeyGenParameterSpec.Builder(ALIAS,
                KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build();
        generator.init(spec);
        return generator.generateKey();
    }

    public static void save(Context context, String toml) throws Exception {
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
        byte[] iv = cipher.getIV();
        byte[] data = cipher.doFinal(toml.getBytes(StandardCharsets.UTF_8));
        prefs(context).edit()
                .putString(KEY_IV, Base64.encodeToString(iv, Base64.DEFAULT))
                .putString(KEY_DATA, Base64.encodeToString(data, Base64.DEFAULT))
                .apply();
    }

    /** 返回 null 表示尚未导入配置 */
    public static String load(Context context) throws Exception {
        String iv = prefs(context).getString(KEY_IV, null);
        String data = prefs(context).getString(KEY_DATA, null);
        if (iv == null || data == null) {
            return null;
        }
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(),
                new GCMParameterSpec(128, Base64.decode(iv, Base64.DEFAULT)));
        return new String(cipher.doFinal(Base64.decode(data, Base64.DEFAULT)), StandardCharsets.UTF_8);
    }

    public static void clear(Context context) {
        prefs(context).edit().clear().apply();
    }

    public static void setEnabled(Context context, boolean enabled) {
        prefs(context).edit().putBoolean(KEY_ENABLED, enabled).apply();
    }

    public static boolean isEnabled(Context context) {
        return prefs(context).getBoolean(KEY_ENABLED, false);
    }
}
