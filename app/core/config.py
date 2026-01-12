from pydantic_settings import BaseSettings
from typing import Optional, Dict, Any
from pydantic import validator
import secrets
from functools import lru_cache

class Settings(BaseSettings):
    # API Settings
    API_V1_STR: str = "/api/v1"
    PROJECT_NAME: str = "Sistema de Integración Alegra"
    VERSION: str = "1.0.0"
    DESCRIPTION: str = "API para la integración entre el sistema local y Alegra"
    
    # Security
    SECRET_KEY: str = secrets.token_urlsafe(32)
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 60 * 24 * 8  # 8 days
    ALGORITHM: str = "HS256"
    
    # CORS
    BACKEND_CORS_ORIGINS: list = ["*"]
    
    # Database
    DB_HOST: str = "26.214.221.240"
    DB_NAME: str = "ware_local"
    DB_USER: str = "kevin"
    DB_PASS: str = "890922"
    DB_POOL_SIZE: int = 5
    DB_MAX_OVERFLOW: int = 10
    
    # Alegra API
    ALEGRA_API_URL: str = "https://api.alegra.com/api/v1"
    ALEGRA_EMAIL: str = "mariamargaritavides@gmail.com"
    ALEGRA_TOKEN: str = "7d63006638f9d02ec1bd"
    
    # Rate Limiting
    RATE_LIMIT_PER_MINUTE: int = 60
    
    # Logging
    LOG_LEVEL: str = "INFO"
    LOG_FORMAT: str = "%(asctime)s - %(name)s - %(levelname)s - %(message)s"
    
    @validator("BACKEND_CORS_ORIGINS", pre=True)
    def assemble_cors_origins(cls, v: str | list[str]) -> list[str]:
        if isinstance(v, str) and not v.startswith("["):
            return [i.strip() for i in v.split(",")]
        elif isinstance(v, (list, str)):
            return v
        raise ValueError(v)
    
    class Config:
        case_sensitive = True
        env_file = ".env"

@lru_cache()
def get_settings() -> Settings:
    return Settings()

settings = get_settings() 