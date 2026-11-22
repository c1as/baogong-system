package com.easytier.jni;

/**
 * EasyTier JNI 接口（对应官方 easytier-android-jni 的 com.easytier.jni.EasyTierJNI.kt）。
 * 由 libeasytier_android_jni.so 提供 native 实现，System.loadLibrary 加载。
 *
 * 注意：本类包名必须是 com.easytier.jni，因为 native 方法符号名
 * (Java_com_easytier_jni_EasyTierJNI_xxx) 与包名/类名强绑定，不能改。
 */
public final class EasyTierJni {

    static {
        System.loadLibrary("easytier_android_jni");
    }

    private EasyTierJni() {
    }

    /** 设置 TUN 文件描述符；instanceName 必须是已 runNetworkInstance 的实例名 */
    public static native int setTunFd(String instanceName, int fd);

    /** 校验 TOML 配置文本，0 成功，-1 失败（失败会抛 RuntimeException） */
    public static native int parseConfig(String config);

    /** 用 TOML 配置启动一个网络实例，0 成功 */
    public static native int runNetworkInstance(String config);

    /** 保留指定名称的实例，其余全部停止；null 或空数组表示全部停止 */
    public static native int retainNetworkInstance(String[] instanceNames);

    /** 停止单个命名实例；名字不存在时无操作 */
    public static native int deleteNetworkInstance(String instanceName);

    /** 收集运行中实例信息，返回 JSON 字符串（NetworkInstanceRunningInfoMap） */
    public static native String collectNetworkInfos(int maxLength);

    /** 列出运行中实例名与 ID，返回 JSON 字符串 */
    public static native String listInstances(int maxLength);

    /** 返回当前线程最近一次 FFI/JNI 错误；无错误返回 null */
    public static native String getLastError();
}
