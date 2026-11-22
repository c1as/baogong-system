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
 * V3 WireGuard 配置存储：私钥绝不硬编码，也不明文落盘。
 * 用 Android Keystore 里的一把 AES-256/GCM 密钥加密整份 .conf，
 * 密文与 IV 存 SharedPreferences；每台设备独立密钥，清除配置即可吊销。
 */
public final class VpnConfigStore {

    private static final String PREFS = "bg_vpn";
    private static final String KEY_IV = "iv";
    private static final String KEY_DATA = "data";
    private static final String ALIAS = "bg_wg_conf_key";
    private static final String ANDROID_KEYSTORE = "AndroidKeyStore";
    private static final String TRANSFORMATION = "AES/GCM/NoPadding";

    private VpnConfigStore() {
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

    public static void save(Context context, String confText) throws Exception {
        Cipher cipher = Cipher.getInstance(TRANSFORMATION);
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey());
        byte[] iv = cipher.getIV();
        byte[] data = cipher.doFinal(confText.getBytes(StandardCharsets.UTF_8));
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
}
