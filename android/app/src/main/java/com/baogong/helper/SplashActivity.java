package com.baogong.helper;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.util.TypedValue;
import android.view.Gravity;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

/**
 * V2 启动页。
 * 窗口背景由 SplashTheme 的 splash_background.xml 提供（避免冷启动白屏），
 * 这里只用纯代码摆出图标、应用名和提示文字，短暂停留后进入 MainActivity。
 * 注意：本类不读取任何服务器地址，地址仍由用户在主界面首次打开时自行填写。
 */
public class SplashActivity extends Activity {

    private static final long DELAY_MS = 900L;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER);
        // 透明，露出 windowBackground 的品牌色
        root.setBackgroundColor(0x00000000);
        root.setPadding(dp(24), dp(24), dp(24), dp(24));

        ImageView icon = new ImageView(this);
        icon.setImageResource(R.drawable.ic_launcher);
        root.addView(icon, new LinearLayout.LayoutParams(dp(96), dp(96)));

        TextView name = new TextView(this);
        name.setText(R.string.app_name);
        name.setTextColor(0xFFFFFFFF);
        name.setTextSize(22);
        name.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams nameLp = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        nameLp.topMargin = dp(18);
        root.addView(name, nameLp);

        TextView version = new TextView(this);
        version.setText("v2.0 · 报工系统手机客户端");
        version.setTextColor(0xCCFFFFFF);
        version.setTextSize(13);
        version.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams verLp = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        verLp.topMargin = dp(6);
        root.addView(version, verLp);

        TextView hint = new TextView(this);
        hint.setText(R.string.splash_hint);
        hint.setTextColor(0xCCFFFFFF);
        hint.setTextSize(13);
        hint.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams hintLp = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        hintLp.topMargin = dp(28);
        root.addView(hint, hintLp);

        setContentView(root);

        new Handler(Looper.getMainLooper()).postDelayed(() -> {
            startActivity(new Intent(this, MainActivity.class));
            finish();
        }, DELAY_MS);
    }

    private int dp(float value) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value,
                getResources().getDisplayMetrics());
    }
}
