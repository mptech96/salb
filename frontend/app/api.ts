import axios from "axios";
import {
  clearAllSessions,
  getAuthToken,
  hasPlatformSessionBackup,
  readSession,
  restorePlatformSession,
} from "@/lib/session";

const api = axios.create({
  baseURL:
    process.env.NEXT_PUBLIC_API_URL ||
    "http://127.0.0.1:8000/api",

  /*
  |--------------------------------------------------------------------------
  | مهم جداً
  |--------------------------------------------------------------------------
  |
  | لا نضع Content-Type هنا.
  |
  | Axios سيحدد تلقائياً:
  |
  | JSON:
  | application/json
  |
  | FormData:
  | multipart/form-data; boundary=....
  |
  | وهذا يحافظ على تسجيل الدخول ويصلح رفع الملفات.
  |
  */
  headers: {
    Accept: "application/json",
  },

  timeout: 30000,
});

api.interceptors.request.use(
  (config) => {
    const token = getAuthToken();

    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    } else if (config.headers.Authorization) {
      delete config.headers.Authorization;
    }

    /*
    |--------------------------------------------------------------------------
    | حماية إضافية للملفات
    |--------------------------------------------------------------------------
    |
    | لو أي شاشة أرسلت FormData،
    | نتأكد أننا لا نفرض Content-Type يدوياً.
    |
    */
    if (
      typeof FormData !== "undefined" &&
      config.data instanceof FormData
    ) {
      if (config.headers) {
        delete config.headers["Content-Type"];
      }
    }

    return config;
  },
  (error) => Promise.reject(error)
);

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = Number(error?.response?.status || 0);
    const requestUrl = String(error?.config?.url || "");

    const isLoginRequest =
      requestUrl.endsWith("/login") ||
      requestUrl === "/login";

    if (
      typeof window !== "undefined" &&
      status === 401 &&
      !isLoginRequest
    ) {
      const currentSession = readSession();

      if (
        currentSession?.user?.is_support_mode &&
        hasPlatformSessionBackup() &&
        restorePlatformSession()
      ) {
        window.location.replace(
          "/system-center/companies?support=expired"
        );
      } else {
        clearAllSessions();

        if (window.location.pathname !== "/login") {
          window.location.replace(
            "/login?session=expired"
          );
        }
      }
    }

    return Promise.reject(error);
  }
);

export default api;