from scrapy.selector import Selector
from PriceMonitor.items import ProductDetails
from PriceMonitor.BaseSpider import BaseSpider

class noriel_roSpider(BaseSpider):
    name = "noriel.ro"
    allowed_domains = ["www.noriel.ro"]
        
    def parse(self, response):
        hxs = Selector(response)
        product = ProductDetails()
        
        # .//*[@id='offer-price-stock']/div[contains(@class,'prices')]
        
        product['id'] = self.get_id(response)
        product['URL'] = response.url
        
        priceStr = None
        if priceStr is None:
            txt = hxs.xpath(".//form//span[@itemprop='price']/strong/span/text()").extract()
            if txt:
                priceStr = txt[0]
            
        if priceStr is None:
            txt = hxs.xpath(".//form//p[@class='special-price']//span[@itemprop='price']/strong/text()").extract()
            if txt:
                priceStr = txt[0]
        
        product['price'] = self.extractPrice("fmt:human:RO,dirty", priceStr)
        product['currency'] = 'RON'
        product['name'] = hxs.xpath(".//h1[@itemprop='name']/text()").extract()[0]
        product['description'] = ''
        product['attributes'] =     self.determineAttributes(product['name'])
        product['extractedData'] = {}
        
        return [product]
    
