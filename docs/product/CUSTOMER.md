# Customer

## Purpose

Customer, Moximu'nun hizmet verdiği gerçek müşteri/kurum kaydıdır. Ajans içi operasyonun kök varlığıdır.

## User value

Ekip bir müşteriyi tek yerden tanır; markalarına, iletişim kişilerine ve sorumlularına hızla ulaşır.

## Core concepts

* Customer = kurumsal/ajans müşterisi (SaaS tenant değildir)
* Customer Contact = müşteri tarafındaki yetkili kişiler
* Responsible Team Members = Moximu içi sorumlu kullanıcılar
* Customer detail zamanla ajansın müşteri çalışma alanına dönüşür

## MVP behavior

* Customer oluştur/listele/görüntüle/düzenle (Admin + yetkili Team Member)
* Temel alanlar: görünen ad, tür, ticari/yasal unvan (opsiyonel), durum, iletişim bilgileri, hizmet başlangıç tarihi, Moximu'dan aldığı hizmetler (basit liste/metin)
* Birden fazla Customer Contact yönetilebilir
* Sorumlu ekip üyeleri atanabilir
* Customer üzerinden Brands listesine gidilebilir
* Notes / tags / attachments **MVP Core zorunluluğu değildir** (ADR-037)

## Important data / attributes

MVP'de operasyonel değeri yüksek: name/display name, type, legal/trade name, status, primary contact channels, service start date, services received, contacts, responsible users.
Schema kilidi yok; implementer framework-native model seçer.

## Relationships

Customer → Contacts  
Customer → Brands  
Customer → Responsible Team Members  
Brand → Digital Assets (Customer üzerinden dolaylı)

## Main screens / workflows

* Customers index
* Customer create/edit
* Customer detail (özet + contacts + brands + sorumlular)
* Detail'den Brand'e geçiş

## Rules / invariants

* Workspace/tenant yok; tüm Customer'lar tek ajans kurulumuna aittir
* Müşteri sisteme giriş yapmaz
* Silme/arşiv politikası veri kaybını önleyecek şekilde ihtiyatlı olmalı
* Attachments/Tags framework'ü Customer için zorunlu Core haline getirilmez

## Derived information

Marka sayısı, açık Finding özetleri vb. ileride related kayıtlardan türetilir; Customer tablosuna gereksiz duplicate kolon gömülmez.

## Later enhancements

Notes, tags, attachments/files, zengin hizmet paketleri, SLA, sözleşme dosyaları, timeline.

## Explicit non-goals

Müşteri portalı, self-service login, multi-tenant billing, SaaS onboarding.

## Acceptance intent

Ajans çalışanı bir Customer kaydı açıp contact ve sorumluları bağlayabilir; markalara geçiş yapabilir; sistem Customer'ı yalnızca name/email CRUD olarak modellemez.
